<?php
	/**
	 * Plugin Name: درگاه بانک ملی Pro-VIP
	 * Plugin URI: https://sadadpsp.ir
	 * Description: درگاه بانک ملی برای Pro-VIP
	 * Version: 1.0
	 * Author: http://almaatech.ir
	 * Author URI: http://almaatech.ir
	 */
	defined('ABSPATH') or exit;

	if (!function_exists('init_melli_gateway_pv_class')) {
		add_action('plugins_loaded', 'init_melli_gateway_pv_class');

		function init_melli_gateway_pv_class() {
			add_filter('pro_vip_currencies_list', 'currencies_check');

			function currencies_check($list) {
				if (!in_array('IRT', $list)) {
					$list['IRT'] = [
							'name' => 'تومان ایران',
							'symbol' => 'تومان',
					];
				}

				if (!in_array('IRR', $list)) {
					$list['IRR'] = [
							'name' => 'ریال ایران',
							'symbol' => 'ریال',
					];
				}

				return $list;
			}

			if (class_exists('Pro_VIP_Payment_Gateway') && !class_exists('Pro_VIP_Melli_Gateway')) {
				class Pro_VIP_Melli_Gateway extends Pro_VIP_Payment_Gateway {
					public $id = 'Melli',
							$settings = [],
							$frontendLabel = 'درگاه بانک ملی',
							$adminLabel = 'درگاه بانک ملی';

					public function __construct() {
						parent::__construct();
					}

					public function beforePayment(Pro_VIP_Payment $payment) {
						$Amount = intval($payment->price); // Required
						$orderId = $payment->paymentId; // Required
						$CallbackURL = add_query_arg('order', $orderId, $this->getReturnUrl());

						if (pvGetOption('currency') === 'IRT') {
							$Amount *= 10;
						}

						$terminal_id = $this->settings['terminal_id'];
						$merchant_id = $this->settings['merchant_id'];
						$terminal_key = $this->settings['terminal_key'];


						$sign_data = $this->sadad_encrypt($terminal_id . ';' . $orderId . ';' . $Amount, $terminal_key);
						$parameters = array(
								'MerchantID' => $merchant_id,
								'TerminalId' => $terminal_id,
								'Amount' => $Amount,
								'OrderId' => $orderId,
								'LocalDateTime' => date('Ymdhis'),
								'ReturnUrl' => $CallbackURL,
								'SignData' => $sign_data,
						);

						$error_flag = false;
						$error_msg = '';

						$result = $this->sadad_call_api('https://sadad.shaparak.ir/VPG/api/v0/Request/PaymentRequest', $parameters);

						if ($result != false) {
							if ($result->ResCode == 0) {
								$payment->key = $orderId;
								$payment->user = get_current_user_id();
								$payment->save();

								$payment_url = 'https://sadad.shaparak.ir/VPG/Purchase?Token=' . $result->Token;

								header("Location: {$payment_url}");

							} else {
								//bank returned an error
								$error_flag = true;
								$error_msg = 'خطا در انتقال به بانک! ' . $this->sadad_request_err_msg($result->ResCode);
							}
						} else {
							// couldn't connect to bank
							$error_flag = true;
							$error_msg = 'خطا! برقراری ارتباط با بانک امکان پذیر نیست.';
						}
						if ($error_flag) {
							pvAddNotice(__($error_msg,'provip'));
							return;
						}
					}

					public function afterPayment() {
						$orderId = isset($_GET['order']) ? $_GET['order'] : 0;

						if ($orderId && isset($_POST['token']) && isset($_POST['OrderId']) && isset($_POST['ResCode'])) {
							$token = $_POST['token'];
							$payment = new Pro_VIP_Payment($orderId);


							//verify payment
							$parameters = array(
									'Token' => $token,
									'SignData' => $this->sadad_encrypt($token, $this->settings['terminal_key']),
							);

							$error_flag = false;
							$error_msg = '';

							$result = $this->sadad_call_api('https://sadad.shaparak.ir/VPG/api/v0/Advice/Verify', $parameters);
							if ($result != false) {
								if ($result->ResCode == 0) {
									pvAddNotice("پرداخت شما با موفقیت و شماره مرجع {$result->RetrivalRefNo} و شماره پیگیری {$result->SystemTraceNo} انجام شد.", 'success');
									$payment->status = 'publish';
									$payment->save();
									$this->paymentComplete($payment);
								} else {
									//couldn't verify the payment due to a back error
									$error_flag = true;
									$error_msg = 'خطا هنگام پرداخت! ' . self::sadad_verify_err_msg($result->ResCode);
								}
							} else {
								//couldn't verify the payment due to a connection failure to bank
								$error_flag = true;
								$error_msg = 'خطا! عدم امکان دریافت تاییدیه پرداخت از بانک';
							}
							if ($error_flag) {
								pvAddNotice(__($error_msg,'provip'));
								$this->paymentFailed($payment);
								return false;
							}

						}

					}

					public function adminSettings(PV_Framework_Form_Builder $form) {
						$form->textfield('merchant_id')->label('شماره پذیرنده');
						$form->textfield('terminal_id')->label('شماره ترمینال');
						$form->textfield('terminal_key')->label('کلید تراکنش');
					}

					private function sadad_encrypt($data, $secret) {
						//Generate a key from a hash
						$key = base64_decode($secret);

						//Pad for PKCS7
						$blockSize = mcrypt_get_block_size('tripledes', 'ecb');
						$len = strlen($data);
						$pad = $blockSize - ($len % $blockSize);
						$data .= str_repeat(chr($pad), $pad);

						//Encrypt data
						$encData = mcrypt_encrypt('tripledes', $key, $data, 'ecb');

						return base64_encode($encData);
					}

					private function sadad_call_api($url, $data = false) {
						$ch = curl_init();
						curl_setopt($ch, CURLOPT_URL, $url);
						curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type:application/json; charset=utf-8'));
						curl_setopt($ch, CURLOPT_POST, 1);
						if ($data) {
							curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
						}
						curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
						curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
						$result = curl_exec($ch);
						curl_close($ch);
						return !empty($result) ? json_decode($result) : false;
					}

					private function sadad_request_err_msg($err_code) {

						$message = 'در حین پرداخت خطای سیستمی رخ داده است .';
						switch ($err_code) {
							case 3:
								$message = 'پذيرنده کارت فعال نیست لطفا با بخش امورپذيرندگان, تماس حاصل فرمائید.';
								break;
							case 23:
								$message = 'پذيرنده کارت نامعتبر است لطفا با بخش امورذيرندگان, تماس حاصل فرمائید.';
								break;
							case 58:
								$message = 'انجام تراکنش مربوطه توسط پايانه ی انجام دهنده مجاز نمی باشد.';
								break;
							case 61:
								$message = 'مبلغ تراکنش از حد مجاز بالاتر است.';
								break;
							case 1000:
								$message = 'ترتیب پارامترهای ارسالی اشتباه می باشد, لطفا مسئول فنی پذيرنده با بانکماس حاصل فرمايند.';
								break;
							case 1001:
								$message = 'لطفا مسئول فنی پذيرنده با بانک تماس حاصل فرمايند,پارامترهای پرداختاشتباه می باشد.';
								break;
							case 1002:
								$message = 'خطا در سیستم- تراکنش ناموفق';
								break;
							case 1003:
								$message = 'آی پی پذیرنده اشتباه است. لطفا مسئول فنی پذیرنده با بانک تماس حاصل فرمایند.';
								break;
							case 1004:
								$message = 'لطفا مسئول فنی پذيرنده با بانک تماس حاصل فرمايند,شماره پذيرندهاشتباه است.';
								break;
							case 1005:
								$message = 'خطای دسترسی:لطفا بعدا تلاش فرمايید.';
								break;
							case 1006:
								$message = 'خطا در سیستم';
								break;
							case 1011:
								$message = 'درخواست تکراری- شماره سفارش تکراری می باشد.';
								break;
							case 1012:
								$message = 'اطلاعات پذيرنده صحیح نیست,يکی از موارد تاريخ,زمان يا کلید تراکنش
                            اشتباه است.لطفا مسئول فنی پذيرنده با بانک تماس حاصل فرمايند.';
								break;
							case 1015:
								$message = 'پاسخ خطای نامشخص از سمت مرکز';
								break;
							case 1017:
								$message = 'مبلغ درخواستی شما جهت پرداخت از حد مجاز تعريف شده برای اين پذيرنده بیشتر است';
								break;
							case 1018:
								$message = 'اشکال در تاريخ و زمان سیستم. لطفا تاريخ و زمان سرور خود را با بانک هماهنگ نمايید';
								break;
							case 1019:
								$message = 'امکان پرداخت از طريق سیستم شتاب برای اين پذيرنده امکان پذير نیست';
								break;
							case 1020:
								$message = 'پذيرنده غیرفعال شده است.لطفا جهت فعال سازی با بانک تماس بگیريد';
								break;
							case 1023:
								$message = 'آدرس بازگشت پذيرنده نامعتبر است';
								break;
							case 1024:
								$message = 'مهر زمانی پذيرنده نامعتبر است';
								break;
							case 1025:
								$message = 'امضا تراکنش نامعتبر است';
								break;
							case 1026:
								$message = 'شماره سفارش تراکنش نامعتبر است';
								break;
							case 1027:
								$message = 'شماره پذيرنده نامعتبر است';
								break;
							case 1028:
								$message = 'شماره ترمینال پذيرنده نامعتبر است';
								break;
							case 1029:
								$message = 'آدرس IP پرداخت در محدوده آدرس های معتبر اعلام شده توسط پذيرنده نیست .لطفا مسئول فنی پذيرنده با بانک تماس حاصل فرمايند';
								break;
							case 1030:
								$message = 'آدرس Domain پرداخت در محدوده آدرس های معتبر اعلام شده توسط پذيرنده نیست .لطفا مسئول فنی پذيرنده با بانک تماس حاصل فرمايند';
								break;
							case 1031:
								$message = 'مهلت زمانی شما جهت پرداخت به پايان رسیده است.لطفا مجددا سعی بفرمايید .';
								break;
							case 1032:
								$message = 'پرداخت با اين کارت . برای پذيرنده مورد نظر شما امکان پذير نیست.لطفا از کارتهای مجاز که توسط پذيرنده معرفی شده است . استفاده نمايید.';
								break;
							case 1033:
								$message = 'به علت مشکل در سايت پذيرنده. پرداخت برای اين پذيرنده غیرفعال شده
                            است.لطفا مسوول فنی سايت پذيرنده با بانک تماس حاصل فرمايند.';
								break;
							case 1036:
								$message = 'اطلاعات اضافی ارسال نشده يا دارای اشکال است';
								break;
							case 1037:
								$message = 'شماره پذيرنده يا شماره ترمینال پذيرنده صحیح نمیباشد';
								break;
							case 1053:
								$message = 'خطا: درخواست معتبر, از سمت پذيرنده صورت نگرفته است لطفا اطلاعات پذيرنده خود را چک کنید.';
								break;
							case 1055:
								$message = 'مقدار غیرمجاز در ورود اطلاعات';
								break;
							case 1056:
								$message = 'سیستم موقتا قطع میباشد.لطفا بعدا تلاش فرمايید.';
								break;
							case 1058:
								$message = 'سرويس پرداخت اينترنتی خارج از سرويس می باشد.لطفا بعدا سعی بفرمايید.';
								break;
							case 1061:
								$message = 'اشکال در تولید کد يکتا. لطفا مرورگر خود را بسته و با اجرای مجدد مرورگر « عملیات پرداخت را انجام دهید )احتمال استفاده از دکمه Back » مرورگر(';
								break;
							case 1064:
								$message = 'لطفا مجددا سعی بفرمايید';
								break;
							case 1065:
								$message = 'ارتباط ناموفق .لطفا چند لحظه ديگر مجددا سعی کنید';
								break;
							case 1066:
								$message = 'سیستم سرويس دهی پرداخت موقتا غیر فعال شده است';
								break;
							case 1068:
								$message = 'با عرض پوزش به علت بروزرسانی . سیستم موقتا قطع میباشد.';
								break;
							case 1072:
								$message = 'خطا در پردازش پارامترهای اختیاری پذيرنده';
								break;
							case 1101:
								$message = 'مبلغ تراکنش نامعتبر است';
								break;
							case 1103:
								$message = 'توکن ارسالی نامعتبر است';
								break;
							case 1104:
								$message = 'اطلاعات تسهیم صحیح نیست';
								break;
							default:
								$message = 'خطای نامشخص';
						}
						return __($message, 'provip');
					}

					private function sadad_verify_err_msg($res_code) {
						$error_text = '';
						switch ($res_code) {
							case -1:
							case '-1':
								$error_text = 'پارامترهای ارسالی صحیح نیست و يا تراکنش در سیستم وجود ندارد.';
								break;
							case 101:
							case '101':
								$error_text = 'مهلت ارسال تراکنش به پايان رسیده است.';
								break;
						}
						return __($error_text, 'provip');
					}

				}

				Pro_VIP_Payment_Gateway::registerGateway('Pro_VIP_Melli_Gateway');
			}
		}
	}
