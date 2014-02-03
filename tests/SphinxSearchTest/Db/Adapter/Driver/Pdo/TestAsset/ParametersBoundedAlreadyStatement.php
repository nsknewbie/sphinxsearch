<?php
/**
 * Sphinx Search
 *
 * @link        https://github.com/ripaclub/zf2-sphinxsearch
 * @copyright   Copyright (c) 2014, Leonardo Di Donato <leodidonato at gmail dot com>, Leonardo Grasso <me at leonardograsso dot com>
 * @license     http://opensource.org/licenses/BSD-2-Clause Simplified BSD License
 */
namespace SphinxSearchTest\Db\Adapter\Driver\Pdo\TestAsset;

use SphinxSearch\Db\Adapter\Driver\Pdo\Statement;

class ParametersBoundedAlreadyStatement extends Statement
{

    /**
     * @var bool
     */
    protected $parametersBound = true;

}