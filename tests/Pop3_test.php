<?php
require_once('../lib/Connection.php');
require_once('../lib/Pop3.php');
require_once('../lib/Exception.php');

use Mail\Protocol\Pop3;

class TestOfPop3 extends UnitTestCase
{
	function testOfPop3TCPConnection()
	{
		$pop3 = new Pop3( 'localhost', 110, 'tcp' );
		$this->assertFalse( $pop3->isConnected() );
		$pop3->connect();
		$this->assertTrue( $pop3->isConnected() );
		$pop3->close();
	}

	function testOfPop3TLSConnection()
	{
		$pop3 = new Pop3( 'localhost', 110, 'tls' );
		$this->assertFalse( $pop3->isConnected() );
		$pop3->connect();
		$this->assertTrue( $pop3->isConnected() );
		$pop3->close();
	}

	function testOfPop3Authentication()
	{
		$pop3 = new Pop3( 'localhost', 110, 'tls' );
		$pop3->connect();
		$pop3->authenticate( 'poptest', 'foobar12' );
		$pop3->close();
	}

	function testOfPop3CAPACommand()
	{
		$pop3 = new Pop3( 'localhost', 110, 'tls' );
		$pop3->connect();
		$this->assertIsA( $pop3->getServerCapabilities( 'raw' ), 'string' );
		$this->assertIsA( $pop3->getServerCapabilities( 'array' ), 'array' );
		$pop3->authenticate( 'poptest', 'foobar12' );
		$this->assertIsA( $pop3->getServerCapabilities( 'raw' ), 'string' );
		$this->assertIsA( $pop3->getServerCapabilities( 'array' ), 'array' );
		$pop3->close();
	}

	function testOfPop3STATCommand()
	{
		$pop3 = new Pop3( 'localhost', 110, 'tls' );
		$pop3->connect();
		$pop3->authenticate( 'poptest', 'foobar12' );
		$this->assertIsA( $pop3->status(), 'array' );
		$pop3->close();
	}

	function testOfPop3LISTCommand()
	{
		$pop3 = new Pop3( 'localhost', 110, 'tls' );
		$pop3->connect();
		$pop3->authenticate( 'poptest', 'foobar12' );
		$this->assertIsA( $pop3->listMessages(), 'array' );
		$this->assertIsA( $pop3->listMessages(1), 'array' );
		$pop3->close();
	}

	function testOfPop3RETRCommand()
	{
		$pop3 = new Pop3( 'localhost', 110, 'tls' );
		$pop3->connect();
		$pop3->authenticate( 'poptest', 'foobar12' );
		$this->assertIsA( $pop3->retrieve(1), 'string' );
		$pop3->close();
	}

	function testOfDELECommand()
	{
		$pop3 = new Pop3( 'localhost', 110, 'tls' );
		$pop3->connect();
		$pop3->authenticate( 'poptest', 'foobar12' );
		$this->assertTrue( $pop3->delete(1) );
		$pop3->reset();
		$pop3->close();
	}

	function testOfRSETCommand()
	{
		$pop3 = new Pop3( 'localhost', 110, 'tls' );
		$pop3->connect();
		$pop3->authenticate( 'poptest', 'foobar12' );
		$pop3->delete(1);
		$this->assertTrue( $pop3->reset() );
		$pop3->close();
	}

	function testOfPop3NOOPCommand()
	{
		$pop3 = new Pop3( 'localhost', 110, 'tls' );
		$pop3->connect();
		$pop3->authenticate( 'poptest', 'foobar12' );
		$this->assertTrue( $pop3->noop() );
		$pop3->close();
	}

	function testOfPop3TOPCommand()
	{
		$pop3 = new Pop3( 'localhost', 110, 'tls' );
		$pop3->connect();
		$pop3->authenticate( 'poptest', 'foobar12' );
		$this->assertIsA( $pop3->top(1), 'string' );
		$pop3->close();
	}

	function testOfPop3UIDLCommand()
	{
		$pop3 = new Pop3( 'localhost', 110, 'tls' );
		$pop3->connect();
		$pop3->authenticate( 'poptest', 'foobar12' );
		$this->assertIsA( $pop3->uidl(), 'array' );
		$this->assertIsA( $pop3->uidl(1), 'array' );
		$pop3->close();
	}

	function testOfPop3QUITCommand()
	{
		$pop3 = new Pop3( 'localhost', 110, 'tls' );
		$pop3->connect();
		$pop3->authenticate( 'poptest', 'foobar12' );
		$this->assertTrue( $pop3->quit() );
		$pop3->close();
	}
}
?>