<?php declare(strict_types=1);
/*
 * Copyright (c) 2010-2014 Pierrick Charron
 * Copyright (c) 2016-2019 Holger Woltersdorf & Contributors
 *
 * Permission is hereby granted, free of charge, to any person obtaining a copy of
 * this software and associated documentation files (the "Software"), to deal in
 * the Software without restriction, including without limitation the rights to
 * use, copy, modify, merge, publish, distribute, sublicense, and/or sell copies
 * of the Software, and to permit persons to whom the Software is furnished to do
 * so, subject to the following conditions:
 *
 * The above copyright notice and this permission notice shall be included in all
 * copies or substantial portions of the Software.
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 * IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 * FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
 * AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 * LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
 * OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE
 * SOFTWARE.
 */

namespace hollodotme\FastCGI\Tests\Unit\Sockets;

use Exception;
use hollodotme\FastCGI\Encoders\NameValuePairEncoder;
use hollodotme\FastCGI\Encoders\PacketEncoder;
use hollodotme\FastCGI\Exceptions\ConnectException;
use hollodotme\FastCGI\Exceptions\ReadFailedException;
use hollodotme\FastCGI\Exceptions\TimedoutException;
use hollodotme\FastCGI\Exceptions\WriteFailedException;
use hollodotme\FastCGI\Interfaces\ProvidesResponseData;
use hollodotme\FastCGI\Requests\PostRequest;
use hollodotme\FastCGI\SocketConnections\UnixDomainSocket;
use hollodotme\FastCGI\Sockets\Socket;
use hollodotme\FastCGI\Tests\Traits\SocketDataProviding;
use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Throwable;
use function dirname;
use function http_build_query;

final class SocketTest extends TestCase
{
	use SocketDataProviding;

	/**
	 * @throws Exception
	 */
	public function testCanGetIdAfterConstruction() : void
	{
		$socket = $this->getSocket();

		$this->assertGreaterThanOrEqual( 1, $socket->getId() );
		$this->assertLessThanOrEqual( (1 << 16) - 1, $socket->getId() );
	}

	/**
	 * @return Socket
	 * @throws Exception
	 */
	private function getSocket() : Socket
	{
		$nameValuePairEncoder = new NameValuePairEncoder();
		$packetEncoder        = new PacketEncoder();
		$connection           = new UnixDomainSocket( $this->getUnixDomainSocket() );

		return new Socket( $connection, $packetEncoder, $nameValuePairEncoder );
	}

	/**
	 * @throws Exception
	 * @throws Throwable
	 * @throws ConnectException
	 * @throws TimedoutException
	 * @throws WriteFailedException
	 */
	public function testCanSendRequestAndFetchResponse() : void
	{
		$socket  = $this->getSocket();
		$data    = ['test-key' => 'unit'];
		$request = new PostRequest(
			dirname( __DIR__, 2 ) . '/Integration/Workers/worker.php',
			http_build_query( $data )
		);

		$socket->sendRequest( $request );

		$response = $socket->fetchResponse();

		$this->assertSame( 'unit', $response->getBody() );

		$response2 = $socket->fetchResponse();

		$this->assertSame( $response, $response2 );
	}

	/**
	 * @throws Exception
	 * @throws AssertionFailedError
	 * @throws \PHPUnit\Framework\Exception
	 * @throws ConnectException
	 * @throws TimedoutException
	 * @throws WriteFailedException
	 */
	public function testCanCollectResource() : void
	{
		$resources = [];
		$socket    = $this->getSocket();
		$data      = ['test-key' => 'unit'];
		$request   = new PostRequest(
			dirname( __DIR__, 2 ) . '/Integration/Workers/worker.php',
			http_build_query( $data )
		);

		$socket->collectResource( $resources );

		$this->assertEmpty( $resources );

		$socket->sendRequest( $request );

		$socket->collectResource( $resources );

		$this->assertIsResource( $resources[ $socket->getId() ] );
	}

	/**
	 * @throws ConnectException
	 * @throws TimedoutException
	 * @throws WriteFailedException
	 * @throws ReadFailedException
	 * @throws Exception
	 */
	public function testCanNotifyResponseCallback() : void
	{
		$socket  = $this->getSocket();
		$data    = ['test-key' => 'unit'];
		$request = new PostRequest(
			dirname( __DIR__, 2 ) . '/Integration/Workers/worker.php',
			http_build_query( $data )
		);
		$request->addResponseCallbacks(
			static function ( ProvidesResponseData $response )
			{
				echo $response->getBody();
			}
		);

		$socket->sendRequest( $request );
		$response = $socket->fetchResponse();
		$socket->notifyResponseCallbacks( $response );

		$this->expectOutputString( 'unit' );
	}

	/**
	 * @throws ConnectException
	 * @throws TimedoutException
	 * @throws WriteFailedException
	 * @throws Exception
	 */
	public function testCanNotifyFailureCallback() : void
	{
		$socket  = $this->getSocket();
		$data    = ['test-key' => 'unit'];
		$request = new PostRequest(
			dirname( __DIR__, 2 ) . '/Integration/Workers/worker.php',
			http_build_query( $data )
		);
		$request->addFailureCallbacks(
			static function ( Throwable $throwable )
			{
				echo $throwable->getMessage();
			}
		);
		$throwable = new RuntimeException( 'Something went wrong.' );

		$socket->sendRequest( $request );
		$socket->notifyFailureCallbacks( $throwable );

		$this->expectOutputString( 'Something went wrong.' );
	}
}