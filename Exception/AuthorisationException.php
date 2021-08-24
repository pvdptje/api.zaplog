<?php

/**
 * Zaplog.COM
 *
 * @link:       api.Zaplog.com
 * @copyright:  VCK TRAVEL BV, 2016
 * @author:     patrick@patricksavalle.com
 *
 * Note: use coding standards at http://www.php-fig.org/psr/
 */

declare(strict_types = 1);

namespace Zaplog\Exception {

    class AuthorisationException extends \Exception
    {
        public function __construct(string $message = "", int $httpcode = 403)
        {
            parent::__construct($message, $httpcode);
        }
    }

}