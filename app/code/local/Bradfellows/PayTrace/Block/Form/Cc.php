<?php


class Bradfellows_PayTrace_Block_Form_Cc extends Mage_Payment_Block_Form_Cc
{

    protected function _construct()
    {
        parent::_construct();
        $this->setTemplate('bradfellows/form/tokenized.phtml');
    }
}