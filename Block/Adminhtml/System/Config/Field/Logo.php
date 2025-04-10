<?php

namespace LeanX\Payments\Block\Adminhtml\System\Config\Field;

use Magento\Config\Block\System\Config\Form\Field;

class Logo extends Field
{
    protected function _getElementHtml(\Magento\Framework\Data\Form\Element\AbstractElement $element)
    {
        $url = $this->getViewFileUrl('LeanX_Payments::images/leanx.png');
        return '<img src="' . $url . '" style="max-height: 20px; vertical-align: middle;" />';
    }
}