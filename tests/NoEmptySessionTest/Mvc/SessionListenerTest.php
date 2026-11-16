<?php declare(strict_types=1);

namespace NoEmptySessionTest\Mvc;

use ArrayObject;
use NoEmptySession\Mvc\SessionListener;
use PHPUnit\Framework\TestCase;

class SessionListenerTest extends TestCase
{
    public function testEmptySessionHasNoData(): void
    {
        $this->assertFalse(SessionListener::hasData([]));
    }

    public function testMetadataOfLaminasIsNotData(): void
    {
        $data = [
            '__Laminas' => [
                '_REQUEST_ACCESS_TIME' => 1794942458.72821,
                '_VALID' => ['Laminas\Session\Validator\Id' => 'abc'],
            ],
        ];
        $this->assertFalse(SessionListener::hasData($data));
    }

    public function testEmptyNamespacesAreNotData(): void
    {
        // Namespaces created by the containers read during the request, like
        // the authentication or the messenger.
        $data = [
            '__Laminas' => ['_REQUEST_ACCESS_TIME' => 1794942458.72821],
            'Laminas_Auth' => new ArrayObject(),
            'OmekaMessenger' => new ArrayObject(['messages' => []]),
            'Other' => ['value' => null, 'string' => ''],
        ];
        $this->assertFalse(SessionListener::hasData($data));
    }

    public function testIdentityIsData(): void
    {
        $data = [
            '__Laminas' => ['_REQUEST_ACCESS_TIME' => 1794942458.72821],
            'Laminas_Auth' => new ArrayObject(['storage' => 1]),
        ];
        $this->assertTrue(SessionListener::hasData($data));
    }

    public function testCsrfTokenIsData(): void
    {
        $data = [
            'Laminas_Validator_Csrf_salt_csrf' => new ArrayObject([
                'tokenList' => ['abc' => 'def'],
                'hash' => 'abcdef',
            ]),
        ];
        $this->assertTrue(SessionListener::hasData($data));
    }

    public function testFalseAndZeroAreData(): void
    {
        $this->assertTrue(SessionListener::hasData(['Module' => ['flag' => false]]));
        $this->assertTrue(SessionListener::hasData(['Module' => ['count' => 0]]));
    }

    public function testMetadataKeyIsSkippedOnlyAtRoot(): void
    {
        $this->assertTrue(SessionListener::hasData(['Module' => ['__Laminas' => 'value']]));
    }
}
