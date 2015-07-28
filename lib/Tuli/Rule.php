<?php

/*
 * This file is part of Tuli, a static analyzer for PHP
 *
 * @copyright 2015 Anthony Ferrara. All rights reserved
 * @license MIT See LICENSE at the root of the project for more info
 */

namespace Tuli;

use PHPTypes\State;

interface Rule {
    
    /**
     * @param State $state
     *
     * @return array
     */
    public function execute(State $state);
    public function getName();

}