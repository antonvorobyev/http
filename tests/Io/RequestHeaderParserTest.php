<?php

namespace React\Tests\Http\Io;

use React\Http\Io\RequestHeaderParser;
use React\Tests\Http\TestCase;

class RequestHeaderParserTest extends TestCase
{
    public function testSplitShouldHappenOnDoubleCrlf()
    {
        $parser = new RequestHeaderParser();
        $parser->on('headers', $this->expectCallableNever());

        $connection = $this->getMockBuilder('React\Socket\Connection')->disableOriginalConstructor()->setMethods(null)->getMock();

        $parser->handle($connection);

        $connection->emit('data', array("GET / HTTP/1.1\r\n"));
        $connection->emit('data', array("Host: example.com:80\r\n"));
        $connection->emit('data', array("Connection: close\r\n"));

        $parser->removeAllListeners();
        $parser->on('headers', $this->expectCallableOnce());

        $connection->emit('data', array("\r\n"));
    }

    public function testFeedInOneGo()
    {
        $parser = new RequestHeaderParser();
        $parser->on('headers', $this->expectCallableOnce());

        $connection = $this->getMockBuilder('React\Socket\Connection')->disableOriginalConstructor()->setMethods(null)->getMock();
        $parser->handle($connection);

        $data = $this->createGetRequest();
        $connection->emit('data', array($data));
    }

    public function testFeedTwoRequestsOnSeparateConnections()
    {
        $parser = new RequestHeaderParser();

        $called = 0;
        $parser->on('headers', function () use (&$called) {
            ++$called;
        });

        $connection1 = $this->getMockBuilder('React\Socket\Connection')->disableOriginalConstructor()->setMethods(null)->getMock();
        $connection2 = $this->getMockBuilder('React\Socket\Connection')->disableOriginalConstructor()->setMethods(null)->getMock();
        $parser->handle($connection1);
        $parser->handle($connection2);

        $data = $this->createGetRequest();
        $connection1->emit('data', array($data));
        $connection2->emit('data', array($data));

        $this->assertEquals(2, $called);
    }

    public function testHeadersEventShouldReturnRequestAndBodyBufferAndConnection()
    {
        $request = null;
        $bodyBuffer = null;
        $conn = null;

        $parser = new RequestHeaderParser();
        $parser->on('headers', function ($parsedRequest, $parsedBodyBuffer, $connection) use (&$request, &$bodyBuffer, &$conn) {
            $request = $parsedRequest;
            $bodyBuffer = $parsedBodyBuffer;
            $conn = $connection;
        });

        $connection = $this->getMockBuilder('React\Socket\Connection')->disableOriginalConstructor()->setMethods(null)->getMock();
        $parser->handle($connection);

        $data = $this->createGetRequest();
        $data .= 'RANDOM DATA';
        $connection->emit('data', array($data));

        $this->assertInstanceOf('Psr\Http\Message\RequestInterface', $request);
        $this->assertSame('GET', $request->getMethod());
        $this->assertEquals('http://example.com/', $request->getUri());
        $this->assertSame('1.1', $request->getProtocolVersion());
        $this->assertSame(array('Host' => array('example.com'), 'Connection' => array('close')), $request->getHeaders());

        $this->assertSame('RANDOM DATA', $bodyBuffer);

        $this->assertSame($connection, $conn);
    }

    public function testHeadersEventShouldReturnBinaryBodyBuffer()
    {
        $bodyBuffer = null;

        $parser = new RequestHeaderParser();
        $parser->on('headers', function ($parsedRequest, $parsedBodyBuffer) use (&$bodyBuffer) {
            $bodyBuffer = $parsedBodyBuffer;
        });

        $connection = $this->getMockBuilder('React\Socket\Connection')->disableOriginalConstructor()->setMethods(null)->getMock();
        $parser->handle($connection);

        $data = $this->createGetRequest();
        $data .= "\0x01\0x02\0x03\0x04\0x05";
        $connection->emit('data', array($data));

        $this->assertSame("\0x01\0x02\0x03\0x04\0x05", $bodyBuffer);
    }

    public function testHeadersEventShouldParsePathAndQueryString()
    {
        $request = null;

        $parser = new RequestHeaderParser();
        $parser->on('headers', function ($parsedRequest, $parsedBodyBuffer) use (&$request) {
            $request = $parsedRequest;
        });

        $connection = $this->getMockBuilder('React\Socket\Connection')->disableOriginalConstructor()->setMethods(null)->getMock();
        $parser->handle($connection);

        $data = $this->createAdvancedPostRequest();
        $connection->emit('data', array($data));

        $this->assertInstanceOf('Psr\Http\Message\RequestInterface', $request);
        $this->assertSame('POST', $request->getMethod());
        $this->assertEquals('http://example.com/foo?bar=baz', $request->getUri());
        $this->assertSame('1.1', $request->getProtocolVersion());
        $headers = array(
            'Host' => array('example.com'),
            'User-Agent' => array('react/alpha'),
            'Connection' => array('close'),
        );
        $this->assertSame($headers, $request->getHeaders());
    }

    public function testHeaderEventWithShouldApplyDefaultAddressFromLocalConnectionAddress()
    {
        $request = null;

        $parser = new RequestHeaderParser();
        $parser->on('headers', function ($parsedRequest) use (&$request) {
            $request = $parsedRequest;
        });

        $connection = $this->getMockBuilder('React\Socket\Connection')->disableOriginalConstructor()->setMethods(array('getLocalAddress'))->getMock();
        $connection->expects($this->once())->method('getLocalAddress')->willReturn('tcp://127.1.1.1:8000');
        $parser->handle($connection);

        $connection->emit('data', array("GET /foo HTTP/1.0\r\n\r\n"));

        $this->assertEquals('http://127.1.1.1:8000/foo', $request->getUri());
        $this->assertEquals('127.1.1.1:8000', $request->getHeaderLine('Host'));
    }

    public function testHeaderEventViaHttpsShouldApplyHttpsSchemeFromLocalTlsConnectionAddress()
    {
        $request = null;

        $parser = new RequestHeaderParser();
        $parser->on('headers', function ($parsedRequest) use (&$request) {
            $request = $parsedRequest;
        });

        $connection = $this->getMockBuilder('React\Socket\Connection')->disableOriginalConstructor()->setMethods(array('getLocalAddress'))->getMock();
        $connection->expects($this->once())->method('getLocalAddress')->willReturn('tls://127.1.1.1:8000');
        $parser->handle($connection);

        $connection->emit('data', array("GET /foo HTTP/1.0\r\nHost: example.com\r\n\r\n"));

        $this->assertEquals('https://example.com/foo', $request->getUri());
        $this->assertEquals('example.com', $request->getHeaderLine('Host'));
    }

    public function testHeaderOverflowShouldEmitError()
    {
        $error = null;
        $passedConnection = null;

        $parser = new RequestHeaderParser();
        $parser->on('headers', $this->expectCallableNever());
        $parser->on('error', function ($message, $connection) use (&$error, &$passedConnection) {
            $error = $message;
            $passedConnection = $connection;
        });

        $connection = $this->getMockBuilder('React\Socket\Connection')->disableOriginalConstructor()->setMethods(null)->getMock();
        $parser->handle($connection);

        $data = str_repeat('A', 8193);
        $connection->emit('data', array($data));

        $this->assertInstanceOf('OverflowException', $error);
        $this->assertSame('Maximum header size of 8192 exceeded.', $error->getMessage());
        $this->assertSame($connection, $passedConnection);
    }

    public function testHeaderOverflowShouldNotEmitErrorWhenDataExceedsMaxHeaderSize()
    {
        $request = null;
        $bodyBuffer = null;

        $parser = new RequestHeaderParser();
        $parser->on('headers', function ($parsedRequest, $parsedBodyBuffer) use (&$request, &$bodyBuffer) {
            $request = $parsedRequest;
            $bodyBuffer = $parsedBodyBuffer;
        });

        $connection = $this->getMockBuilder('React\Socket\Connection')->disableOriginalConstructor()->setMethods(null)->getMock();
        $parser->handle($connection);

        $data = $this->createAdvancedPostRequest();
        $body = str_repeat('A', 8193 - strlen($data));
        $data .= $body;
        $connection->emit('data', array($data));

        $headers = array(
            'Host' => array('example.com'),
            'User-Agent' => array('react/alpha'),
            'Connection' => array('close'),
        );
        $this->assertSame($headers, $request->getHeaders());

        $this->assertSame($body, $bodyBuffer);
    }

    public function testInvalidEmptyRequestHeadersParseException()
    {
        $error = null;

        $parser = new RequestHeaderParser();
        $parser->on('headers', $this->expectCallableNever());
        $parser->on('error', function ($message) use (&$error) {
            $error = $message;
        });

        $connection = $this->getMockBuilder('React\Socket\Connection')->disableOriginalConstructor()->setMethods(null)->getMock();
        $parser->handle($connection);

        $connection->emit('data', array("\r\n\r\n"));

        $this->assertInstanceOf('InvalidArgumentException', $error);
        $this->assertSame('Unable to parse invalid request-line', $error->getMessage());
    }

    public function testInvalidMalformedRequestLineParseException()
    {
        $error = null;

        $parser = new RequestHeaderParser();
        $parser->on('headers', $this->expectCallableNever());
        $parser->on('error', function ($message) use (&$error) {
            $error = $message;
        });

        $connection = $this->getMockBuilder('React\Socket\Connection')->disableOriginalConstructor()->setMethods(null)->getMock();
        $parser->handle($connection);

        $connection->emit('data', array("GET /\r\n\r\n"));

        $this->assertInstanceOf('InvalidArgumentException', $error);
        $this->assertSame('Unable to parse invalid request-line', $error->getMessage());
    }

    public function testInvalidAbsoluteFormSchemeEmitsError()
    {
        $error = null;

        $parser = new RequestHeaderParser();
        $parser->on('headers', $this->expectCallableNever());
        $parser->on('error', function ($message) use (&$error) {
            $error = $message;
        });

        $connection = $this->getMockBuilder('React\Socket\Connection')->disableOriginalConstructor()->setMethods(null)->getMock();
        $parser->handle($connection);

        $connection->emit('data', array("GET tcp://example.com:80/ HTTP/1.0\r\n\r\n"));

        $this->assertInstanceOf('InvalidArgumentException', $error);
        $this->assertSame('Invalid absolute-form request-target', $error->getMessage());
    }

    public function testOriginFormWithSchemeSeparatorInParam()
    {
        $request = null;

        $parser = new RequestHeaderParser();
        $parser->on('error', $this->expectCallableNever());
        $parser->on('headers', function ($parsedRequest, $parsedBodyBuffer) use (&$request) {
            $request = $parsedRequest;
        });

        $connection = $this->getMockBuilder('React\Socket\Connection')->disableOriginalConstructor()->setMethods(null)->getMock();
        $parser->handle($connection);

        $connection->emit('data', array("GET /somepath?param=http://example.com HTTP/1.1\r\nHost: localhost\r\n\r\n"));

        $this->assertInstanceOf('Psr\Http\Message\RequestInterface', $request);
        $this->assertSame('GET', $request->getMethod());
        $this->assertEquals('http://localhost/somepath?param=http://example.com', $request->getUri());
        $this->assertSame('1.1', $request->getProtocolVersion());
        $headers = array(
            'Host' => array('localhost')
        );
        $this->assertSame($headers, $request->getHeaders());
    }

    public function testUriStartingWithColonSlashSlashFails()
    {
        $error = null;

        $parser = new RequestHeaderParser();
        $parser->on('headers', $this->expectCallableNever());
        $parser->on('error', function ($message) use (&$error) {
            $error = $message;
        });

        $connection = $this->getMockBuilder('React\Socket\Connection')->disableOriginalConstructor()->setMethods(null)->getMock();
        $parser->handle($connection);

        $connection->emit('data', array("GET ://example.com:80/ HTTP/1.0\r\n\r\n"));

        $this->assertInstanceOf('InvalidArgumentException', $error);
        $this->assertSame('Invalid request string', $error->getMessage());
    }

    public function testInvalidAbsoluteFormWithFragmentEmitsError()
    {
        $error = null;

        $parser = new RequestHeaderParser();
        $parser->on('headers', $this->expectCallableNever());
        $parser->on('error', function ($message) use (&$error) {
            $error = $message;
        });

        $connection = $this->getMockBuilder('React\Socket\Connection')->disableOriginalConstructor()->setMethods(null)->getMock();
        $parser->handle($connection);

        $connection->emit('data', array("GET http://example.com:80/#home HTTP/1.0\r\n\r\n"));

        $this->assertInstanceOf('InvalidArgumentException', $error);
        $this->assertSame('Invalid absolute-form request-target', $error->getMessage());
    }

    public function testInvalidHeaderContainsFullUri()
    {
        $error = null;

        $parser = new RequestHeaderParser();
        $parser->on('headers', $this->expectCallableNever());
        $parser->on('error', function ($message) use (&$error) {
            $error = $message;
        });

        $connection = $this->getMockBuilder('React\Socket\Connection')->disableOriginalConstructor()->setMethods(null)->getMock();
        $parser->handle($connection);

        $connection->emit('data', array("GET / HTTP/1.1\r\nHost: http://user:pass@host/\r\n\r\n"));

        $this->assertInstanceOf('InvalidArgumentException', $error);
        $this->assertSame('Invalid Host header value', $error->getMessage());
    }

    public function testInvalidAbsoluteFormWithHostHeaderEmpty()
    {
        $error = null;

        $parser = new RequestHeaderParser();
        $parser->on('headers', $this->expectCallableNever());
        $parser->on('error', function ($message) use (&$error) {
            $error = $message;
        });

        $connection = $this->getMockBuilder('React\Socket\Connection')->disableOriginalConstructor()->setMethods(null)->getMock();
        $parser->handle($connection);

        $connection->emit('data', array("GET http://example.com/ HTTP/1.1\r\nHost: \r\n\r\n"));

        $this->assertInstanceOf('InvalidArgumentException', $error);
        $this->assertSame('Invalid Host header value', $error->getMessage());
    }

    public function testInvalidConnectRequestWithNonAuthorityForm()
    {
        $error = null;

        $parser = new RequestHeaderParser();
        $parser->on('headers', $this->expectCallableNever());
        $parser->on('error', function ($message) use (&$error) {
            $error = $message;
        });

        $connection = $this->getMockBuilder('React\Socket\Connection')->disableOriginalConstructor()->setMethods(null)->getMock();
        $parser->handle($connection);

        $connection->emit('data', array("CONNECT http://example.com:8080/ HTTP/1.1\r\nHost: example.com:8080\r\n\r\n"));

        $this->assertInstanceOf('InvalidArgumentException', $error);
        $this->assertSame('CONNECT method MUST use authority-form request target', $error->getMessage());
    }

    public function testInvalidHttpVersion()
    {
        $error = null;

        $parser = new RequestHeaderParser();
        $parser->on('headers', $this->expectCallableNever());
        $parser->on('error', function ($message) use (&$error) {
            $error = $message;
        });

        $connection = $this->getMockBuilder('React\Socket\Connection')->disableOriginalConstructor()->setMethods(null)->getMock();
        $parser->handle($connection);

        $connection->emit('data', array("GET / HTTP/1.2\r\n\r\n"));

        $this->assertInstanceOf('InvalidArgumentException', $error);
        $this->assertSame(505, $error->getCode());
        $this->assertSame('Received request with invalid protocol version', $error->getMessage());
    }

    public function testServerParamsWillBeSetOnHttpsRequest()
    {
        $request = null;

        $parser = new RequestHeaderParser();

        $parser->on('headers', function ($parsedRequest) use (&$request) {
            $request = $parsedRequest;
        });

        $connection = $this->getMockBuilder('React\Socket\Connection')->disableOriginalConstructor()->setMethods(array('getLocalAddress', 'getRemoteAddress'))->getMock();
        $connection->expects($this->once())->method('getLocalAddress')->willReturn('tls://127.1.1.1:8000');
        $connection->expects($this->once())->method('getRemoteAddress')->willReturn('tls://192.168.1.1:8001');
        $parser->handle($connection);

        $connection->emit('data', array("GET /foo HTTP/1.0\r\nHost: example.com\r\n\r\n"));

        $serverParams = $request->getServerParams();

        $this->assertEquals('on', $serverParams['HTTPS']);
        $this->assertNotEmpty($serverParams['REQUEST_TIME']);
        $this->assertNotEmpty($serverParams['REQUEST_TIME_FLOAT']);

        $this->assertEquals('127.1.1.1', $serverParams['SERVER_ADDR']);
        $this->assertEquals('8000', $serverParams['SERVER_PORT']);

        $this->assertEquals('192.168.1.1', $serverParams['REMOTE_ADDR']);
        $this->assertEquals('8001', $serverParams['REMOTE_PORT']);
    }

    public function testServerParamsWillBeSetOnHttpRequest()
    {
        $request = null;

        $parser = new RequestHeaderParser();

        $parser->on('headers', function ($parsedRequest) use (&$request) {
            $request = $parsedRequest;
        });

        $connection = $this->getMockBuilder('React\Socket\Connection')->disableOriginalConstructor()->setMethods(array('getLocalAddress', 'getRemoteAddress'))->getMock();
        $connection->expects($this->once())->method('getLocalAddress')->willReturn('tcp://127.1.1.1:8000');
        $connection->expects($this->once())->method('getRemoteAddress')->willReturn('tcp://192.168.1.1:8001');
        $parser->handle($connection);

        $connection->emit('data', array("GET /foo HTTP/1.0\r\nHost: example.com\r\n\r\n"));

        $serverParams = $request->getServerParams();

        $this->assertArrayNotHasKey('HTTPS', $serverParams);
        $this->assertNotEmpty($serverParams['REQUEST_TIME']);
        $this->assertNotEmpty($serverParams['REQUEST_TIME_FLOAT']);

        $this->assertEquals('127.1.1.1', $serverParams['SERVER_ADDR']);
        $this->assertEquals('8000', $serverParams['SERVER_PORT']);

        $this->assertEquals('192.168.1.1', $serverParams['REMOTE_ADDR']);
        $this->assertEquals('8001', $serverParams['REMOTE_PORT']);
    }

    public function testServerParamsWillNotSetRemoteAddressForUnixDomainSockets()
    {
        $request = null;

        $parser = new RequestHeaderParser();

        $parser->on('headers', function ($parsedRequest) use (&$request) {
            $request = $parsedRequest;
        });

        $connection = $this->getMockBuilder('React\Socket\Connection')->disableOriginalConstructor()->setMethods(array('getLocalAddress', 'getRemoteAddress'))->getMock();
        $connection->expects($this->once())->method('getLocalAddress')->willReturn('unix://./server.sock');
        $connection->expects($this->once())->method('getRemoteAddress')->willReturn(null);
        $parser->handle($connection);

        $connection->emit('data', array("GET /foo HTTP/1.0\r\nHost: example.com\r\n\r\n"));

        $serverParams = $request->getServerParams();

        $this->assertArrayNotHasKey('HTTPS', $serverParams);
        $this->assertNotEmpty($serverParams['REQUEST_TIME']);
        $this->assertNotEmpty($serverParams['REQUEST_TIME_FLOAT']);

        $this->assertArrayNotHasKey('SERVER_ADDR', $serverParams);
        $this->assertArrayNotHasKey('SERVER_PORT', $serverParams);

        $this->assertArrayNotHasKey('REMOTE_ADDR', $serverParams);
        $this->assertArrayNotHasKey('REMOTE_PORT', $serverParams);
    }

    public function testServerParamsWontBeSetOnMissingUrls()
    {
        $request = null;

        $parser = new RequestHeaderParser();

        $parser->on('headers', function ($parsedRequest) use (&$request) {
            $request = $parsedRequest;
        });

        $connection = $this->getMockBuilder('React\Socket\Connection')->disableOriginalConstructor()->setMethods(null)->getMock();
        $parser->handle($connection);

        $connection->emit('data', array("GET /foo HTTP/1.0\r\nHost: example.com\r\n\r\n"));

        $serverParams = $request->getServerParams();

        $this->assertNotEmpty($serverParams['REQUEST_TIME']);
        $this->assertNotEmpty($serverParams['REQUEST_TIME_FLOAT']);

        $this->assertArrayNotHasKey('SERVER_ADDR', $serverParams);
        $this->assertArrayNotHasKey('SERVER_PORT', $serverParams);

        $this->assertArrayNotHasKey('REMOTE_ADDR', $serverParams);
        $this->assertArrayNotHasKey('REMOTE_PORT', $serverParams);
    }

    public function testQueryParmetersWillBeSet()
    {
        $request = null;

        $parser = new RequestHeaderParser();

        $parser->on('headers', function ($parsedRequest) use (&$request) {
            $request = $parsedRequest;
        });

        $connection = $this->getMockBuilder('React\Socket\Connection')->disableOriginalConstructor()->setMethods(null)->getMock();
        $parser->handle($connection);

        $connection->emit('data', array("GET /foo.php?hello=world&test=this HTTP/1.0\r\nHost: example.com\r\n\r\n"));

        $queryParams = $request->getQueryParams();

        $this->assertEquals('world', $queryParams['hello']);
        $this->assertEquals('this', $queryParams['test']);
    }

    private function createGetRequest()
    {
        $data = "GET / HTTP/1.1\r\n";
        $data .= "Host: example.com:80\r\n";
        $data .= "Connection: close\r\n";
        $data .= "\r\n";

        return $data;
    }

    private function createAdvancedPostRequest()
    {
        $data = "POST /foo?bar=baz HTTP/1.1\r\n";
        $data .= "Host: example.com:80\r\n";
        $data .= "User-Agent: react/alpha\r\n";
        $data .= "Connection: close\r\n";
        $data .= "\r\n";

        return $data;
    }
}
