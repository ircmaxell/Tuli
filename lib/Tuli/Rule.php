<?php

namespace Tuli;

interface Rule {
    
    public function execute(array $components);
    public function getName();

}