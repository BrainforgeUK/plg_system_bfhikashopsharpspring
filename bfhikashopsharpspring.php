<?php
/**
 * @package    HikaShop for Joomla!
 * @author    https://www.brainforge.co.uk
 * @copyright  (C) 2020 Jonathan Brain. All rights reserved.
 * @license    GNU/GPLv3 http://www.gnu.org/licenses/gpl-3.0.html
 */

use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\CMS\Uri\Uri;

defined('_JEXEC') or die('Restricted access');

class plgSystemBfhikashopsharpspring extends CMSPlugin
{
	const javascriptStore = 'plgSystemBfsharpspring.javascriptStore';

	protected $app;

	public function __construct(&$subject, $config) {
		parent::__construct($subject, $config);

		$this->app = Factory::getApplication();
	}

	public function onAfterOrderUpdate(&$order) {
		$fullOrder = $this->getFullOrder($order->order_id);

		if (
			(isset($fullOrder->order_type) && $fullOrder->order_type != 'sale') ||
			(isset($order->old->order_type) && $order->old->order_type != 'sale') ||
			!isset($order->order_status)
		   )
		{
			return true;
		}

		$paymentMethods = $this->params->get('paymentmethods');
		if (empty($paymentMethods) || !in_array($fullOrder->order_payment_id, $paymentMethods))
		{
			return true;
		}

		$payment_params = hikashop_unserialize($fullOrder->payment->payment_params);
		if (empty($payment_params->verified_status))
		{
			$config = hikashop_config();
			$confirmed_status = $config->get('order_confirmed_status', 'confirmed');
		}
		else
		{
			$confirmed_status = $payment_params->verified_status;
		}

		if ($order->order_status != $confirmed_status ||
			(isset($order->old->order_status) && $order->old->order_status == $confirmed_status)
		   )
		{
			return true;
		}

		Factory::getApplication()->setUserState(plgSystemBfhikashopsharpspring::javascriptStore,
			$this->getScript($fullOrder));

		return true;
	}

	public function onAfterRender() {
		$app = Factory::getApplication();

		$script = $app->getUserState(plgSystemBfhikashopsharpspring::javascriptStore);
		if (empty($script))
		{
			return true;
		}
		$app->setUserState(plgSystemBfhikashopsharpspring::javascriptStore, null);

		$app->setBody(
			str_replace('</head>', $script . '</head>', $app->getBody())
		);

		return true;
	}

	public function onTriggerPlugSharpspringtest()
	{
		if ($this->testlink = $this->params->get('testlink'))
		{
			ob_clean();

			$order_id = $this->app->input->get('order_id', $this->params->get('testorderid'));

			$script = $this->getScript($this->getFullOrder($order_id, false));
			Factory::getApplication()->setUserState(plgSystemBfhikashopsharpspring::javascriptStore, $script);

			Factory::getLanguage()->load('plg_system_bfhikashopsharpspring', __DIR__);

			if (empty($script))
			{
				$this->app->enqueueMessage(Text::sprintf('PLG_SYSTEM_BFSHARPSPRING_TEST_FAIL',
					$order_id, Uri::getInstance()->toString()), 'error');
			}
			else
			{
				$this->app->enqueueMessage(Text::sprintf('PLG_SYSTEM_BFSHARPSPRING_TEST_SUCCESS',
					$order_id, Uri::getInstance()->toString(), htmlspecialchars($script)));
			}
		}

		$this->app->redirect('index.php');
	}

	protected function getFullOrder($order_id, $checkuser=true)
	{
		if (empty($order_id))
		{
			return null;
		}

		$fullOrder = hikashop_get('class.order')->loadFullOrder($order_id, false, $checkuser);
		if (empty($fullOrder))
		{
			return null;
		}

		$fullOrder->totalTax = 0;
		if (!empty($fullOrder->order_tax_info))
		{
			foreach ($fullOrder->order_tax_info as $taxCode => $tax)
			{
				$fullOrder->totalTax += $tax->tax_amount;
				$fullOrder->totalTax += $tax->tax_amount_for_shipping;
			}
		}

		return $fullOrder;
	}

	protected function cleanText($text)
	{
		$text = strip_tags($text);
		return str_replace('"', '\\"', $text);
	}

	/**
	 * Be better to use curl instead of Javascript for this.
	 * Look for examples of using curl with Google Analytics collection.
	 */
	protected function getScript($fullOrder, $testMode=null)
	{
		if (empty($fullOrder))
		{
			return null;
		}

		$transactionIdField = $this->params->get('transactionidfield');
		$transactionId = $this->cleanText($this->params->get('transactionidprefix') . $fullOrder->$transactionIdField);

		$script = '
<script type="text/javascript">
try {
';

		$script .= '
	var _ss = _ss || [];
	_ss.push(["_setDomain", '	. '"' .	$this->params->get('domain') . '"]);
	_ss.push(["_setAccount", '	. '"' .	$this->params->get('account') . '"]);
	_ss.push(["_trackPageView"]);
';
		if ($testMode == 'alert')
		{
			$script .= '
	alert("setAccount : ' .	$this->params->get('account') . '");
';
		}

		$sscript = preg_replace('@^http[s]{0,1}://@i', '',
								trim($this->params->get('clientssscript')));
		$script .= '
	(function() {
		var ss = document.createElement("script");
		ss.type = "text/javascript"; ss.async = true;
		ss.src = ("https:" == document.location.protocol ? "https://" : "http://") + "' . $sscript . '";
  		var scr = document.getElementsByTagName(\'script\')[0];
		var scr = document.getElementsByTagName(\'script\')[0];
		scr.parentNode.insertBefore(ss, scr);
	})();
';

		$script .= '
	_ss.push(["_setTransaction", {
	"transactionID" : '	. '"' .	$transactionId . '",
	"storeName" : '		. '"' .	$this->params->get('storename') . '",
	"total" : '			. '"' .	$fullOrder->order_full_price . '",
	"tax" : '			. '"' .	$fullOrder->totalTax . '",
	"shipping" : '		. '"' .	$fullOrder->order_shipping_price . '",
	"city" : '			. '"' .	$this->cleanText($fullOrder->billing_address->address_city) . '",
	"state" : '			. '"' .	$this->cleanText($fullOrder->billing_address->address_state) . '",
	"zipcode" : '		. '"' .	$this->cleanText($fullOrder->billing_address->address_post_code) . '",
	"country" : '		. '"' .	$this->cleanText($fullOrder->billing_address->address_country) . '",
	"firstName" : '		. '"' .	$this->cleanText($fullOrder->billing_address->address_firstname) . '",
	"lastName" : '		. '"' .	$this->cleanText($fullOrder->billing_address->address_lastname) . '",
	"emailAddress" : '	. '"' .	$this->cleanText($fullOrder->customer->user_email) . '",
	}]);
';

		if ($testMode == 'alert')
		{
			$script .= '
	alert("setTransaction : ' . $transactionId . '");
';
		}

		foreach ($fullOrder->products as $key => $product)
		{
			$script .= '
	_ss.push(["_addTransactionItem", {
	"transactionID": '	. '"' .	$transactionId . '",
	"itemCode": '		. '"' .	$this->cleanText($product->order_product_code) . '",
	"productName": '	. '"' .	$this->cleanText($product->order_product_name) . '",
	"category": '		. '"' .	$this->cleanText('') . '",
	"price": '			. '"' .	$product->order_product_price . '",
	"quantity": '		. '"' .	$product->order_product_quantity . '",
	}])
';

			if ($testMode == 'alert')
			{
				$script .= '
	alert("addTransactionItem : ' . $this->cleanText($product->order_product_code) . '");
';
			}
		}

		$script .= '
	_ss.push(["_completeTransaction", {
	"transactionID": '		. '"' .	$this->cleanText($transactionId) . '"
	}]);
';

		if ($testMode == 'alert')
		{
			$script .= '
	alert("completeTransaction");
';
		}

		$script .= '
} catch (err) {
	alert("Error : " + err.message);
}
</script>
';

		return $script;
	}
}
