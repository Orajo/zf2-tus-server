<?php

/**
 * This file is part of the ZfTusServer package.
 *
 * (c) Jarosław Wasilewski <orajo@windowslive.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */
 
namespace ZfTusServer\Exception; 

/**
 * IOException interface for file and input/output stream related exceptions thrown by the component.
 *
 * @author Christian Gärtner <christiangaertner.film@googlemail.com>
 */
interface IOExceptionInterface extends ExceptionInterface
{
    /**
     * Returns the associated path for the exception.
     *
     * @return string The path.
     */
    public function getPath();
}
