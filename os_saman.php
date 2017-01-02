<?php

defined('_JEXEC') or die();
require_once JPATH_SITE . '/components/com_eshop/plugins/payment/os_trangell_inputcheck.php';

class os_saman extends os_payment
{

	public function __construct($params) {
        $config = array(
            'type' => 0,
            'show_card_type' => false,
            'show_card_holder_name' => false
        );
        $this->setData('samanmerchantId',$params->get('samanmerchantId'));
   
        parent::__construct($params, $config);
	}

	public function processPayment($data) {
		$app	= JFactory::getApplication();
		$dateTime = JFactory::getDate();
			
		$merchantId = $this->data['samanmerchantId'];
		$reservationNumber = time();
		$totalAmount =  $data['total'];
		$callBackUrl  = JURI::root().'index.php?option=com_eshop&task=checkout.verifyPayment&payment_method=os_saman&id='.$data['order_id'];
		$sendUrl = "https\://sep.shaparak.ir/Payment.aspx";
		
		echo '
			<script>
				var form = document.createElement("form");
				form.setAttribute("method", "POST");
				form.setAttribute("action", "'.$sendUrl.'");
				form.setAttribute("target", "_self");

				var hiddenField1 = document.createElement("input");
				hiddenField1.setAttribute("name", "Amount");
				hiddenField1.setAttribute("value", "'.$totalAmount.'");
				form.appendChild(hiddenField1);
				
				var hiddenField2 = document.createElement("input");
				hiddenField2.setAttribute("name", "MID");
				hiddenField2.setAttribute("value", "'.$merchantId.'");
				form.appendChild(hiddenField2);
				
				var hiddenField3 = document.createElement("input");
				hiddenField3.setAttribute("name", "ResNum");
				hiddenField3.setAttribute("value", "'.$reservationNumber.'");
				form.appendChild(hiddenField3);
				
				var hiddenField4 = document.createElement("input");
				hiddenField4.setAttribute("name", "RedirectURL");
				hiddenField4.setAttribute("value", "'.$callBackUrl.'");
				form.appendChild(hiddenField4);
				

				document.body.appendChild(form);
				form.submit();
				document.body.removeChild(form);
			</script>'
		;
	}

	protected function validate($id) {
		$app	= JFactory::getApplication();		
		$allData = EshopHelper::getOrder(intval($id)); //get all data
		//$mobile = $allData->telephone;
		$jinput = JFactory::getApplication()->input;
		$resNum = $jinput->post->get('ResNum', '0', 'INT');
		$trackingCode = $jinput->post->get('TRACENO', '0', 'INT');
		$stateCode = $jinput->post->get('stateCode', '1', 'INT');
		
		$refNum = $jinput->post->get('RefNum', 'empty', 'STRING');
		if (checkHack::strip($refNum) != $refNum )
			$refNum = "illegal";
		$state = $jinput->post->get('State', 'empty', 'STRING');
		if (checkHack::strip($state) != $state )
			$state = "illegal";
		$cardNumber = $jinput->post->get('SecurePan', 'empty', 'STRING'); 
		if (checkHack::strip($cardNumber) != $cardNumber )
			$cardNumber = "illegal";
			
		$price = $allData->total;	
		$merchantId = $this->data['samanmerchantId'];

		$this->logGatewayData(
			'OrderID:' . $id . 
			'resNum:' . $resNum . 
			'refNum:'.$refNum.  
			'state:'.$state.
			'trackingCode:'.$trackingCode.
			'stateCode:'.$stateCode.
			'cardNumber:'.$cardNumber.
			'OrderTime:'.time() 
		);
		if (
			checkHack::checkNum($id) &&
			checkHack::checkNum($resNum) &&
			checkHack::checkNum($trackingCode) &&
			checkHack::checkNum($stateCode) 
		){
			if (isset($state) && ($state == 'OK' || $stateCode == 0)) {
				try {
					$out    = new SoapClient('https://sep.shaparak.ir/payments/referencepayment.asmx?WSDL');
					$resultCode    = $out->VerifyTransaction($refNum, $merchantId);
				
					if ($resultCode == round($price,4)) {
						$this->onPaymentSuccess($id, $trackingCode); 
						$msg= $this->getGateMsg(1); 
						$link = JRoute::_(JUri::root().'index.php?option=com_eshop&view=checkout&layout=complete',false);
						$app->redirect($link, '<h2>'.$msg.'</h2>'.'<h3>'. $trackingCode .'شماره پیگری ' .'</h3>' , $msgType='Message'); 
						return true;
					}
					else {
						$msg= $this->getGateMsg($state); 
						$link = JRoute::_(JUri::root().'index.php?option=com_eshop&view=checkout&layout=cancel',false);
						$app->redirect($link, '<h2>'.$msg.'</h2>', $msgType='Error'); 
						return false;	
					}
				}
				catch(\SoapFault $e)  {
					$msg= $this->getGateMsg('error'); 
					$app	= JFactory::getApplication();
					$link = JRoute::_(JUri::root().'index.php?option=com_eshop&view=checkout&layout=cancel',false);
					$app->redirect($link, '<h2>'.$msg.'</h2>', $msgType='Error'); 
					return false;
				}
			}
			else {
				$msg= $this->getGateMsg($state);
				$link = JRoute::_(JUri::root().'index.php?option=com_eshop&view=checkout&layout=cancel',false);
				$app->redirect($link, '<h2>'.$msg.'</h2>', $msgType='Error'); 
				return false;	
			}
		}
		else {
			$msg= $this->getGateMsg('hck2'); 
			$app	= JFactory::getApplication();
			$link = JRoute::_(JUri::root().'index.php?option=com_eshop&view=checkout&layout=cancel',false);
			$app->redirect($link, '<h2>'.$msg.'</h2>' , $msgType='Error'); 
			return false;	
		}
	}

	public function verifyPayment() {
		$jinput = JFactory::getApplication()->input;
		$id = $jinput->get->get('id', '0', 'INT');
		$row = JTable::getInstance('Eshop', 'Order');
		$row->load($id);
		if ($row->order_status_id == EshopHelper::getConfigValue('complete_status_id'))
				return false;
				
		$this->validate($id);
	}

	public function getGateMsg ($msgId) {
		switch($msgId){
			case '-1': $out=  'خطای داخل شبکه مالی'; break;
			case '-2': $out=  'سپردها برابر نیستند'; break;
			case '-3': $out=  'ورودی های حاوی کاراکترهای غیر مجاز می باشد'; break;
			case '-4': $out=  'کلمه عبور یا کد فروشنده اشتباه است'; break;
			case '-5': $out=  'Database excetion'; break;
			case '-6': $out=  'سند قبلا برگشت کامل یافته است'; break;
			case '-7': $out=  'رسید دیجیتالی تهی است'; break;
			case '-8': $out=  'طول ورودی های بیش از حد مجاز است'; break;
			case '-9': $out=  'وجود کاراکترهای غیر مجاز در مبلغ برگشتی'; break;
			case '-10': $out=  'رسید دیجیتالی حاوی کاراکترهای غیر مجاز است'; break;
			case '-11': $out=  'طول ورودی های کمتر از حد مجاز است'; break;
			case '-12': $out=  'مبلغ برگشت منفی است'; break;
			case '-13': $out=  'مبلغ برگشتی برای برگشت جزیی بیش از مبلغ برگشت نخورده رسید دیجیتالی است'; break;
			case '-14': $out=  'چنین تراکنشی تعریف نشده است'; break;
			case '-15': $out=  'مبلغ برگشتی به صورت اعشاری داده شده است'; break;
			case '-16': $out=  'خطای داخلی سیستم'; break;
			case '-17': $out=  'برگشت زدن جزیی تراکنشی که با کارت بانکی غیر از بانک سامان انجام پذیرفته است'; break;
			case '-18': $out=  'IP Adderess‌ فروشنده نامعتبر'; break;
			case 'Canceled By User': $out=  'تراکنش توسط خریدار کنسل شده است'; break;
			case 'Invalid Amount': $out=  'مبلغ سند برگشتی از مبلغ تراکنش اصلی بیشتر است'; break;
			case 'Invalid Transaction': $out=  'درخواست برگشت یک تراکنش رسیده است . در حالی که تراکنش اصلی پیدا نمی شود.'; break;
			case 'Invalid Card Number': $out=  'شماره کارت اشتباه است'; break;
			case 'No Such Issuer': $out=  'چنین صادر کننده کارتی وجود ندارد'; break;
			case 'Expired Card Pick Up': $out=  'از تاریخ انقضا کارت گذشته است و کارت دیگر معتبر نیست'; break;
			case 'Allowable PIN Tries Exceeded Pick Up': $out=  'رمز (PIN) کارت ۳ بار اشتباه وارد شده است در نتیجه کارت غیر فعال خواهد شد.'; break;
			case 'Incorrect PIN': $out=  'خریدار رمز کارت (PIN) را اشتباه وارده کرده است'; break;
			case 'Exceeds Withdrawal Amount Limit': $out=  'مبلغ بیش از سقف برداشت می باشد'; break;
			case 'Transaction Cannot Be Completed': $out=  'تراکنش تایید شده است ولی امکان سند خوردن وجود ندارد'; break;
			case 'Response Received Too Late': $out=  'تراکنش در شبکه بانکی  timeout خورده است'; break;
			case 'Suspected Fraud Pick Up': $out=  'خریدار فیلد CVV2 یا تاریخ انقضا را اشتباه وارد کرده و یا اصلا وارد نکرده است.'; break;
			case 'No Sufficient Funds': $out=  'موجودی به اندازه کافی در حساب وجود ندارد'; break;
			case 'Issuer Down Slm': $out=  'سیستم کارت بانک صادر کننده در وضعیت عملیاتی نیست'; break;
			case 'TME Error': $out=  'کلیه خطاهای دیگر بانکی که باعث ایجاد چنین خطایی می گردد'; break;
			case '1': $out=  'تراکنش با موفقیت انجام شده است'; break;
			case 'error': $out ='خطا غیر منتظره رخ داده است';break;
			case 'hck2': $out = 'لطفا از کاراکترهای مجاز استفاده کنید';break;
		}
		return $out;
	}
}
