<?php

class Bradfellows_PayTrace_Block_Tokenized_Info
    extends Mage_Payment_Block_Info_Cc
{

    protected function _construct()
    {
        parent::_construct();
        $this->setTemplate('bradfellows/info/tokenized.phtml');
    }

}