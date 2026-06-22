<?php
namespace Cpc\ITop\AcmeManager\Model;

class Endpoint
{
    public function __construct(
        public string $name,
        public string $host,
        public string $user,
        public string $baseDestination
    ) {
    }
}
