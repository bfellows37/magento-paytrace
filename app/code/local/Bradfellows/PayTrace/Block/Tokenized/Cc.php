<?php

class Bradfellows_PayTrace_Block_Tokenized_Cc 
    extends Mage_Paygate_Block_Authorizenet_Form_Cc
{
    
    /**
     * Retreive payment method form html
     *
     * @return string
     */
    public function getMethodFormBlock()
    {
        return $this->getLayout()->createBlock('paytrace/form_cc')
            ->setMethod($this->getMethod());
    }
    
    
    public function hasVerification() {
        return false; // default was true
    }

}
