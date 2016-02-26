<?php

namespace MediaWiki\Session;

use MediaWikiTestCase;
use User;

/**
 * @group Session
 * @group Database
 * @covers MediaWiki\Session\SessionBackend
 */
class SessionBackendTest extends MediaWikiTestCase {
	const SESSIONID = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

	protected $manager;
	protected $config;
	protected $provider;
	protected $store;

	protected $onSessionMetadataCalled = false;

	/**
	 * Returns a non-persistent backend that thinks it has at least one session active
	 * @param User|null $user
	 */
	protected function getBackend( User $user = null ) {
		if ( !$this->config ) {
			$this->config = new \HashConfig();
			$this->manager = null;
		}
		if ( !$this->store ) {
			$this->store = new TestBagOStuff();
			$this->manager = null;
		}

		$logger = new \Psr\Log\NullLogger();
		if ( !$this->manager ) {
			$this->manager = new SessionManager( [
				'store' => $this->store,
				'logger' => $logger,
				'config' => $this->config,
			] );
		}

		if ( !$this->provider ) {
			$this->provider = new \DummySessionProvider();
		}
		$this->provider->setLogger( $logger );
		$this->provider->setConfig( $this->config );
		$this->provider->setManager( $this->manager );

		$info = new SessionInfo( SessionInfo::MIN_PRIORITY, [
			'provider' => $this->provider,
			'id' => self::SESSIONID,
			'persisted' => true,
			'userInfo' => UserInfo::newFromUser( $user ?: new User, true ),
			'idIsSafe' => true,
		] );
		$id = new SessionId( $info->getId() );

		$backend = new SessionBackend( $id, $info, $this->store, $logger, 10 );
		$priv = \TestingAccessWrapper::newFromObject( $backend );
		$priv->persist = false;
		$priv->requests = [ 100 => new \FauxRequest() ];
		$priv->usePhpSessionHandling = false;

		$manager = \TestingAccessWrapper::newFromObject( $this->manager );
		$manager->allSessionBackends = [ $backend->getId() => $backend ];
		$manager->allSessionIds = [ $backend->getId() => $id ];
		$manager->sessionProviders = [ (string)$this->provider => $this->provider ];

		return $backend;
	}

	public function testConstructor() {
		// Set variables
		$this->getBackend();

		$info = new SessionInfo( SessionInfo::MIN_PRIORITY, [
			'provider' => $this->provider,
			'id' => self::SESSIONID,
			'persisted' => true,
			'userInfo' => UserInfo::newFromName( 'UTSysop', false ),
			'idIsSafe' => true,
		] );
		$id = new SessionId( $info->getId() );
		$logger = new \Psr\Log\NullLogger();
		try {
			new SessionBackend( $id, $info, $this->store, $logger, 10 );
			$this->fail( 'Expected exception not thrown' );
		} catch ( \InvalidArgumentException $ex ) {
			$this->assertSame(
				"Refusing to create session for unverified user {$info->getUserInfo()}",
				$ex->getMessage()
			);
		}

		$info = new SessionInfo( SessionInfo::MIN_PRIORITY, [
			'id' => self::SESSIONID,
			'userInfo' => UserInfo::newFromName( 'UTSysop', true ),
			'idIsSafe' => true,
		] );
		$id = new SessionId( $info->getId() );
		try {
			new SessionBackend( $id, $info, $this->store, $logger, 10 );
			$this->fail( 'Expected exception not thrown' );
		} catch ( \InvalidArgumentException $ex ) {
			$this->assertSame( 'Cannot create session without a provider', $ex->getMessage() );
		}

		$info = new SessionInfo( SessionInfo::MIN_PRIORITY, [
			'provider' => $this->provider,
			'id' => self::SESSIONID,
			'persisted' => true,
			'userInfo' => UserInfo::newFromName( 'UTSysop', true ),
			'idIsSafe' => true,
		] );
		$id = new SessionId( '!' . $info->getId() );
		try {
			new SessionBackend( $id, $info, $this->store, $logger, 10 );
			$this->fail( 'Expected exception not thrown' );
		} catch ( \InvalidArgumentException $ex ) {
			$this->assertSame(
				'SessionId and SessionInfo don\'t match',
				$ex->getMessage()
			);
		}

		$info = new SessionInfo( SessionInfo::MIN_PRIORITY, [
			'provider' => $this->provider,
			'id' => self::SESSIONID,
			'persisted' => true,
			'userInfo' => UserInfo::newFromName( 'UTSysop', true ),
			'idIsSafe' => true,
		] );
		$id = new SessionId( $info->getId() );
		$backend = new SessionBackend( $id, $info, $this->store, $logger, 10 );
		$this->assertSame( self::SESSIONID, $backend->getId() );
		$this->assertSame( $id, $backend->getSessionId() );
		$this->assertSame( $this->provider, $backend->getProvider() );
		$this->assertInstanceOf( 'User', $backend->getUser() );
		$this->assertSame( 'UTSysop', $backend->getUser()->getName() );
		$this->assertSame( $info->wasPersisted(), $backend->isPersistent() );
		$this->assertSame( $info->wasRemembered(), $backend->shouldRememberUser() );
		$this->assertSame( $info->forceHTTPS(), $backend->shouldForceHTTPS() );

		$expire = time() + 100;
		$this->store->setSessionMeta( self::SESSIONID, [ 'expires' => $expire ], 2 );

		$info = new SessionInfo( SessionInfo::MIN_PRIORITY, [
			'provider' => $this->provider,
			'id' => self::SESSIONID,
			'persisted' => true,
			'forceHTTPS' => true,
			'metadata' => [ 'foo' ],
			'idIsSafe' => true,
		] );
		$id = new SessionId( $info->getId() );
		$backend = new SessionBackend( $id, $info, $this->store, $logger, 10 );
		$this->assertSame( self::SESSIONID, $backend->getId() );
		$this->assertSame( $id, $backend->getSessionId() );
		$this->assertSame( $this->provider, $backend->getProvider() );
		$this->assertInstanceOf( 'User', $backend->getUser() );
		$this->assertTrue( $backend->getUser()->isAnon() );
		$this->assertSame( $info->wasPersisted(), $backend->isPersistent() );
		$this->assertSame( $info->wasRemembered(), $backend->shouldRememberUser() );
		$this->assertSame( $info->forceHTTPS(), $backend->shouldForceHTTPS() );
		$this->assertSame( $expire, \TestingAccessWrapper::newFromObject( $backend )->expires );
		$this->assertSame( [ 'foo' ], $backend->getProviderMetadata() );
	}

	public function testSessionStuff() {
		$backend = $this->getBackend();
		$priv = \TestingAccessWrapper::newFromObject( $backend );
		$priv->requests = []; // Remove dummy session

		$manager = \TestingAccessWrapper::newFromObject( $this->manager );

		$request1 = new \FauxRequest();
		$session1 = $backend->getSession( $request1 );
		$request2 = new \FauxRequest();
		$session2 = $backend->getSession( $request2 );

		$this->assertInstanceOf( 'MediaWiki\\Session\\Session', $session1 );
		$this->assertInstanceOf( 'MediaWiki\\Session\\Session', $session2 );
		$this->assertSame( 2, count( $priv->requests ) );

		$index = \TestingAccessWrapper::newFromObject( $session1 )->index;

		$this->assertSame( $request1, $backend->getRequest( $index ) );
		$this->assertSame( null, $backend->suggestLoginUsername( $index ) );
		$request1->setCookie( 'UserName', 'Example' );
		$this->assertSame( 'Example', $backend->suggestLoginUsername( $index ) );

		$session1 = null;
		$this->assertSame( 1, count( $priv->requests ) );
		$this->assertArrayHasKey( $backend->getId(), $manager->allSessionBackends );
		$this->assertSame( $backend, $manager->allSessionBackends[$backend->getId()] );
		try {
			$backend->getRequest( $index );
			$this->fail( 'Expected exception not thrown' );
		} catch ( \InvalidArgumentException $ex ) {
			$this->assertSame( 'Invalid session index', $ex->getMessage() );
		}
		try {
			$backend->suggestLoginUsername( $index );
			$this->fail( 'Expected exception not thrown' );
		} catch ( \InvalidArgumentException $ex ) {
			$this->assertSame( 'Invalid session index', $ex->getMessage() );
		}

		$session2 = null;
		$this->assertSame( 0, count( $priv->requests ) );
		$this->assertArrayNotHasKey( $backend->getId(), $manager->allSessionBackends );
		$this->assertArrayHasKey( $backend->getId(), $manager->allSessionIds );
	}

	public function testSetProviderMetadata() {
		$backend = $this->getBackend();
		$priv = \TestingAccessWrapper::newFromObject( $backend );
		$priv->providerMetadata = [ 'dummy' ];

		try {
			$backend->setProviderMetadata( 'foo' );
			$this->fail( 'Expected exception not thrown' );
		} catch ( \InvalidArgumentException $ex ) {
			$this->assertSame( '$metadata must be an array or null', $ex->getMessage() );
		}

		try {
			$backend->setProviderMetadata( (object)[] );
			$this->fail( 'Expected exception not thrown' );
		} catch ( \InvalidArgumentException $ex ) {
			$this->assertSame( '$metadata must be an array or null', $ex->getMessage() );
		}

		$this->assertFalse( $this->store->getSession( self::SESSIONID ), 'sanity check' );
		$backend->setProviderMetadata( [ 'dummy' ] );
		$this->assertFalse( $this->store->getSession( self::SESSIONID ) );

		$this->assertFalse( $this->store->getSession( self::SESSIONID ), 'sanity check' );
		$backend->setProviderMetadata( [ 'test' ] );
		$this->assertNotFalse( $this->store->getSession( self::SESSIONID ) );
		$this->assertSame( [ 'test' ], $backend->getProviderMetadata() );
		$this->store->deleteSession( self::SESSIONID );

		$this->assertFalse( $this->store->getSession( self::SESSIONID ), 'sanity check' );
		$backend->setProviderMetadata( null );
		$this->assertNotFalse( $this->store->getSession( self::SESSIONID ) );
		$this->assertSame( null, $backend->getProviderMetadata() );
		$this->store->deleteSession( self::SESSIONID );
	}

	public function testResetId() {
		$id = session_id();

		$builder = $this->getMockBuilder( 'DummySessionProvider' )
			->setMethods( [ 'persistsSessionId', 'sessionIdWasReset' ] );

		$this->provider = $builder->getMock();
		$this->provider->expects( $this->any() )->method( 'persistsSessionId' )
			->will( $this->returnValue( false ) );
		$this->provider->expects( $this->never() )->method( 'sessionIdWasReset' );
		$backend = $this->getBackend( User::newFromName( 'UTSysop' ) );
		$manager = \TestingAccessWrapper::newFromObject( $this->manager );
		$sessionId = $backend->getSessionId();
		$backend->resetId();
		$this->assertSame( self::SESSIONID, $backend->getId() );
		$this->assertSame( $backend->getId(), $sessionId->getId() );
		$this->assertSame( $id, session_id() );
		$this->assertSame( $backend, $manager->allSessionBackends[self::SESSIONID] );

		$this->provider = $builder->getMock();
		$this->provider->expects( $this->any() )->method( 'persistsSessionId' )
			->will( $this->returnValue( true ) );
		$backend = $this->getBackend();
		$this->provider->expects( $this->once() )->method( 'sessionIdWasReset' )
			->with( $this->identicalTo( $backend ), $this->identicalTo( self::SESSIONID ) );
		$manager = \TestingAccessWrapper::newFromObject( $this->manager );
		$sessionId = $backend->getSessionId();
		$backend->resetId();
		$this->assertNotEquals( self::SESSIONID, $backend->getId() );
		$this->assertSame( $backend->getId(), $sessionId->getId() );
		$this->assertInternalType( 'array', $this->store->getSession( $backend->getId() ) );
		$this->assertFalse( $this->store->getSession( self::SESSIONID ) );
		$this->assertSame( $id, session_id() );
		$this->assertArrayNotHasKey( self::SESSIONID, $manager->allSessionBackends );
		$this->assertArrayHasKey( $backend->getId(), $manager->allSessionBackends );
		$this->assertSame( $backend, $manager->allSessionBackends[$backend->getId()] );
	}

	public function testPersist() {
		$this->provider = $this->getMock( 'DummySessionProvider', [ 'persistSession' ] );
		$this->provider->expects( $this->once() )->method( 'persistSession' );
		$backend = $this->getBackend();
		$this->assertFalse( $backend->isPersistent(), 'sanity check' );
		$backend->save(); // This one shouldn't call $provider->persistSession()

		$backend->persist();
		$this->assertTrue( $backend->isPersistent(), 'sanity check' );

		$this->provider = null;
		$backend = $this->getBackend();
		$wrap = \TestingAccessWrapper::newFromObject( $backend );
		$wrap->persist = true;
		$wrap->expires = 0;
		$backend->persist();
		$this->assertNotEquals( 0, $wrap->expires );
	}

	public function testRememberUser() {
		$backend = $this->getBackend();

		$remembered = $backend->shouldRememberUser();
		$backend->setRememberUser( !$remembered );
		$this->assertNotEquals( $remembered, $backend->shouldRememberUser() );
		$backend->setRememberUser( $remembered );
		$this->assertEquals( $remembered, $backend->shouldRememberUser() );
	}

	public function testForceHTTPS() {
		$backend = $this->getBackend();

		$force = $backend->shouldForceHTTPS();
		$backend->setForceHTTPS( !$force );
		$this->assertNotEquals( $force, $backend->shouldForceHTTPS() );
		$backend->setForceHTTPS( $force );
		$this->assertEquals( $force, $backend->shouldForceHTTPS() );
	}

	public function testLoggedOutTimestamp() {
		$backend = $this->getBackend();

		$backend->setLoggedOutTimestamp( 42 );
		$this->assertSame( 42, $backend->getLoggedOutTimestamp() );
		$backend->setLoggedOutTimestamp( '123' );
		$this->assertSame( 123, $backend->getLoggedOutTimestamp() );
	}

	public function testSetUser() {
		$user = User::newFromName( 'UTSysop' );

		$this->provider = $this->getMock( 'DummySessionProvider', [ 'canChangeUser' ] );
		$this->provider->expects( $this->any() )->method( 'canChangeUser' )
			->will( $this->returnValue( false ) );
		$backend = $this->getBackend();
		$this->assertFalse( $backend->canSetUser() );
		try {
			$backend->setUser( $user );
			$this->fail( 'Expected exception not thrown' );
		} catch ( \BadMethodCallException $ex ) {
			$this->assertSame(
				'Cannot set user on this session; check $session->canSetUser() first',
				$ex->getMessage()
			);
		}
		$this->assertNotSame( $user, $backend->getUser() );

		$this->provider = null;
		$backend = $this->getBackend();
		$this->assertTrue( $backend->canSetUser() );
		$this->assertNotSame( $user, $backend->getUser(), 'sanity check' );
		$backend->setUser( $user );
		$this->assertSame( $user, $backend->getUser() );
	}

	public function testDirty() {
		$backend = $this->getBackend();
		$priv = \TestingAccessWrapper::newFromObject( $backend );
		$priv->dataDirty = false;
		$backend->dirty();
		$this->assertTrue( $priv->dataDirty );
	}

	public function testGetData() {
		$backend = $this->getBackend();
		$data = $backend->getData();
		$this->assertSame( [], $data );
		$this->assertTrue( \TestingAccessWrapper::newFromObject( $backend )->dataDirty );
		$data['???'] = '!!!';
		$this->assertSame( [ '???' => '!!!' ], $data );

		$testData = [ 'foo' => 'foo!', 'bar', [ 'baz', null ] ];
		$this->store->setSessionData( self::SESSIONID, $testData );
		$backend = $this->getBackend();
		$this->assertSame( $testData, $backend->getData() );
		$this->assertFalse( \TestingAccessWrapper::newFromObject( $backend )->dataDirty );
	}

	public function testAddData() {
		$backend = $this->getBackend();
		$priv = \TestingAccessWrapper::newFromObject( $backend );

		$priv->data = [ 'foo' => 1 ];
		$priv->dataDirty = false;
		$backend->addData( [ 'foo' => 1 ] );
		$this->assertSame( [ 'foo' => 1 ], $priv->data );
		$this->assertFalse( $priv->dataDirty );

		$priv->data = [ 'foo' => 1 ];
		$priv->dataDirty = false;
		$backend->addData( [ 'foo' => '1' ] );
		$this->assertSame( [ 'foo' => '1' ], $priv->data );
		$this->assertTrue( $priv->dataDirty );

		$priv->data = [ 'foo' => 1 ];
		$priv->dataDirty = false;
		$backend->addData( [ 'bar' => 2 ] );
		$this->assertSame( [ 'foo' => 1, 'bar' => 2 ], $priv->data );
		$this->assertTrue( $priv->dataDirty );
	}

	public function testDelaySave() {
		$this->mergeMwGlobalArrayValue( 'wgHooks', [ 'SessionMetadata' => [ $this ] ] );
		$backend = $this->getBackend();
		$priv = \TestingAccessWrapper::newFromObject( $backend );
		$priv->persist = true;

		// Saves happen normally when no delay is in effect
		$this->onSessionMetadataCalled = false;
		$priv->metaDirty = true;
		$backend->save();
		$this->assertTrue( $this->onSessionMetadataCalled, 'sanity check' );

		$this->onSessionMetadataCalled = false;
		$priv->metaDirty = true;
		$priv->autosave();
		$this->assertTrue( $this->onSessionMetadataCalled, 'sanity check' );

		$delay = $backend->delaySave();

		// Autosave doesn't happen when no delay is in effect
		$this->onSessionMetadataCalled = false;
		$priv->metaDirty = true;
		$priv->autosave();
		$this->assertFalse( $this->onSessionMetadataCalled );

		// Save still does happen when no delay is in effect
		$priv->save();
		$this->assertTrue( $this->onSessionMetadataCalled );

		// Save happens when delay is consumed
		$this->onSessionMetadataCalled = false;
		$priv->metaDirty = true;
		\ScopedCallback::consume( $delay );
		$this->assertTrue( $this->onSessionMetadataCalled );

		// Test multiple delays
		$delay1 = $backend->delaySave();
		$delay2 = $backend->delaySave();
		$delay3 = $backend->delaySave();
		$this->onSessionMetadataCalled = false;
		$priv->metaDirty = true;
		$priv->autosave();
		$this->assertFalse( $this->onSessionMetadataCalled );
		\ScopedCallback::consume( $delay3 );
		$this->assertFalse( $this->onSessionMetadataCalled );
		\ScopedCallback::consume( $delay1 );
		$this->assertFalse( $this->onSessionMetadataCalled );
		\ScopedCallback::consume( $delay2 );
		$this->assertTrue( $this->onSessionMetadataCalled );
	}

	public function testSave() {
		$user = User::newFromName( 'UTSysop' );
		$this->store = new TestBagOStuff();
		$testData = [ 'foo' => 'foo!', 'bar', [ 'baz', null ] ];

		$neverHook = $this->getMock( __CLASS__, [ 'onSessionMetadata' ] );
		$neverHook->expects( $this->never() )->method( 'onSessionMetadata' );

		$neverProvider = $this->getMock( 'DummySessionProvider', [ 'persistSession' ] );
		$neverProvider->expects( $this->never() )->method( 'persistSession' );

		// Not persistent or dirty
		$this->provider = $neverProvider;
		$this->mergeMwGlobalArrayValue( 'wgHooks', [ 'SessionMetadata' => [ $neverHook ] ] );
		$this->store->setSessionData( self::SESSIONID, $testData );
		$backend = $this->getBackend( $user );
		$this->store->deleteSession( self::SESSIONID );
		$this->assertFalse( $backend->isPersistent(), 'sanity check' );
		\TestingAccessWrapper::newFromObject( $backend )->metaDirty = false;
		\TestingAccessWrapper::newFromObject( $backend )->dataDirty = false;
		$backend->save();
		$this->assertFalse( $this->store->getSession( self::SESSIONID ), 'making sure it didn\'t save' );

		// Not persistent, but dirty
		$this->provider = $neverProvider;
		$this->onSessionMetadataCalled = false;
		$this->mergeMwGlobalArrayValue( 'wgHooks', [ 'SessionMetadata' => [ $this ] ] );
		$this->store->setSessionData( self::SESSIONID, $testData );
		$backend = $this->getBackend( $user );
		$this->store->deleteSession( self::SESSIONID );
		$this->assertFalse( $backend->isPersistent(), 'sanity check' );
		\TestingAccessWrapper::newFromObject( $backend )->metaDirty = false;
		\TestingAccessWrapper::newFromObject( $backend )->dataDirty = true;
		$backend->save();
		$this->assertTrue( $this->onSessionMetadataCalled );
		$blob = $this->store->getSession( self::SESSIONID );
		$this->assertInternalType( 'array', $blob );
		$this->assertArrayHasKey( 'metadata', $blob );
		$metadata = $blob['metadata'];
		$this->assertInternalType( 'array', $metadata );
		$this->assertArrayHasKey( '???', $metadata );
		$this->assertSame( '!!!', $metadata['???'] );
		$this->assertFalse( $this->store->getSessionFromBackend( self::SESSIONID ),
			'making sure it didn\'t save to backend' );

		// Persistent, not dirty
		$this->provider = $neverProvider;
		$this->mergeMwGlobalArrayValue( 'wgHooks', [ 'SessionMetadata' => [ $neverHook ] ] );
		$this->store->setSessionData( self::SESSIONID, $testData );
		$backend = $this->getBackend( $user );
		$this->store->deleteSession( self::SESSIONID );
		\TestingAccessWrapper::newFromObject( $backend )->persist = true;
		$this->assertTrue( $backend->isPersistent(), 'sanity check' );
		\TestingAccessWrapper::newFromObject( $backend )->metaDirty = false;
		\TestingAccessWrapper::newFromObject( $backend )->dataDirty = false;
		$backend->save();
		$this->assertFalse( $this->store->getSession( self::SESSIONID ), 'making sure it didn\'t save' );

		$this->provider = $this->getMock( 'DummySessionProvider', [ 'persistSession' ] );
		$this->provider->expects( $this->atLeastOnce() )->method( 'persistSession' );
		$this->mergeMwGlobalArrayValue( 'wgHooks', [ 'SessionMetadata' => [ $neverHook ] ] );
		$this->store->setSessionData( self::SESSIONID, $testData );
		$backend = $this->getBackend( $user );
		$this->store->deleteSession( self::SESSIONID );
		\TestingAccessWrapper::newFromObject( $backend )->persist = true;
		\TestingAccessWrapper::newFromObject( $backend )->forcePersist = true;
		$this->assertTrue( $backend->isPersistent(), 'sanity check' );
		\TestingAccessWrapper::newFromObject( $backend )->metaDirty = false;
		\TestingAccessWrapper::newFromObject( $backend )->dataDirty = false;
		$backend->save();
		$this->assertFalse( $this->store->getSession( self::SESSIONID ), 'making sure it didn\'t save' );

		// Persistent and dirty
		$this->provider = $neverProvider;
		$this->onSessionMetadataCalled = false;
		$this->mergeMwGlobalArrayValue( 'wgHooks', [ 'SessionMetadata' => [ $this ] ] );
		$this->store->setSessionData( self::SESSIONID, $testData );
		$backend = $this->getBackend( $user );
		$this->store->deleteSession( self::SESSIONID );
		\TestingAccessWrapper::newFromObject( $backend )->persist = true;
		$this->assertTrue( $backend->isPersistent(), 'sanity check' );
		\TestingAccessWrapper::newFromObject( $backend )->metaDirty = false;
		\TestingAccessWrapper::newFromObject( $backend )->dataDirty = true;
		$backend->save();
		$this->assertTrue( $this->onSessionMetadataCalled );
		$blob = $this->store->getSession( self::SESSIONID );
		$this->assertInternalType( 'array', $blob );
		$this->assertArrayHasKey( 'metadata', $blob );
		$metadata = $blob['metadata'];
		$this->assertInternalType( 'array', $metadata );
		$this->assertArrayHasKey( '???', $metadata );
		$this->assertSame( '!!!', $metadata['???'] );
		$this->assertNotSame( false, $this->store->getSessionFromBackend( self::SESSIONID ),
			'making sure it did save to backend' );

		$this->provider = $this->getMock( 'DummySessionProvider', [ 'persistSession' ] );
		$this->provider->expects( $this->atLeastOnce() )->method( 'persistSession' );
		$this->onSessionMetadataCalled = false;
		$this->mergeMwGlobalArrayValue( 'wgHooks', [ 'SessionMetadata' => [ $this ] ] );
		$this->store->setSessionData( self::SESSIONID, $testData );
		$backend = $this->getBackend( $user );
		$this->store->deleteSession( self::SESSIONID );
		\TestingAccessWrapper::newFromObject( $backend )->persist = true;
		\TestingAccessWrapper::newFromObject( $backend )->forcePersist = true;
		$this->assertTrue( $backend->isPersistent(), 'sanity check' );
		\TestingAccessWrapper::newFromObject( $backend )->metaDirty = false;
		\TestingAccessWrapper::newFromObject( $backend )->dataDirty = true;
		$backend->save();
		$this->assertTrue( $this->onSessionMetadataCalled );
		$blob = $this->store->getSession( self::SESSIONID );
		$this->assertInternalType( 'array', $blob );
		$this->assertArrayHasKey( 'metadata', $blob );
		$metadata = $blob['metadata'];
		$this->assertInternalType( 'array', $metadata );
		$this->assertArrayHasKey( '???', $metadata );
		$this->assertSame( '!!!', $metadata['???'] );
		$this->assertNotSame( false, $this->store->getSessionFromBackend( self::SESSIONID ),
			'making sure it did save to backend' );

		$this->provider = $this->getMock( 'DummySessionProvider', [ 'persistSession' ] );
		$this->provider->expects( $this->atLeastOnce() )->method( 'persistSession' );
		$this->onSessionMetadataCalled = false;
		$this->mergeMwGlobalArrayValue( 'wgHooks', [ 'SessionMetadata' => [ $this ] ] );
		$this->store->setSessionData( self::SESSIONID, $testData );
		$backend = $this->getBackend( $user );
		$this->store->deleteSession( self::SESSIONID );
		\TestingAccessWrapper::newFromObject( $backend )->persist = true;
		$this->assertTrue( $backend->isPersistent(), 'sanity check' );
		\TestingAccessWrapper::newFromObject( $backend )->metaDirty = true;
		\TestingAccessWrapper::newFromObject( $backend )->dataDirty = false;
		$backend->save();
		$this->assertTrue( $this->onSessionMetadataCalled );
		$blob = $this->store->getSession( self::SESSIONID );
		$this->assertInternalType( 'array', $blob );
		$this->assertArrayHasKey( 'metadata', $blob );
		$metadata = $blob['metadata'];
		$this->assertInternalType( 'array', $metadata );
		$this->assertArrayHasKey( '???', $metadata );
		$this->assertSame( '!!!', $metadata['???'] );
		$this->assertNotSame( false, $this->store->getSessionFromBackend( self::SESSIONID ),
			'making sure it did save to backend' );

		// Not marked dirty, but dirty data
		// (e.g. indirect modification from ArrayAccess::offsetGet)
		$this->provider = $neverProvider;
		$this->onSessionMetadataCalled = false;
		$this->mergeMwGlobalArrayValue( 'wgHooks', [ 'SessionMetadata' => [ $this ] ] );
		$this->store->setSessionData( self::SESSIONID, $testData );
		$backend = $this->getBackend( $user );
		$this->store->deleteSession( self::SESSIONID );
		\TestingAccessWrapper::newFromObject( $backend )->persist = true;
		$this->assertTrue( $backend->isPersistent(), 'sanity check' );
		\TestingAccessWrapper::newFromObject( $backend )->metaDirty = false;
		\TestingAccessWrapper::newFromObject( $backend )->dataDirty = false;
		\TestingAccessWrapper::newFromObject( $backend )->dataHash = 'Doesn\'t match';
		$backend->save();
		$this->assertTrue( $this->onSessionMetadataCalled );
		$blob = $this->store->getSession( self::SESSIONID );
		$this->assertInternalType( 'array', $blob );
		$this->assertArrayHasKey( 'metadata', $blob );
		$metadata = $blob['metadata'];
		$this->assertInternalType( 'array', $metadata );
		$this->assertArrayHasKey( '???', $metadata );
		$this->assertSame( '!!!', $metadata['???'] );
		$this->assertNotSame( false, $this->store->getSessionFromBackend( self::SESSIONID ),
			'making sure it did save to backend' );

		// Bad hook
		$this->provider = null;
		$mockHook = $this->getMock( __CLASS__, [ 'onSessionMetadata' ] );
		$mockHook->expects( $this->any() )->method( 'onSessionMetadata' )
			->will( $this->returnCallback(
				function ( SessionBackend $backend, array &$metadata, array $requests ) {
					$metadata['userId']++;
				}
			) );
		$this->mergeMwGlobalArrayValue( 'wgHooks', [ 'SessionMetadata' => [ $mockHook ] ] );
		$this->store->setSessionData( self::SESSIONID, $testData );
		$backend = $this->getBackend( $user );
		$backend->dirty();
		try {
			$backend->save();
			$this->fail( 'Expected exception not thrown' );
		} catch ( \UnexpectedValueException $ex ) {
			$this->assertSame(
				'SessionMetadata hook changed metadata key "userId"',
				$ex->getMessage()
			);
		}

		// SessionManager::preventSessionsForUser
		\TestingAccessWrapper::newFromObject( $this->manager )->preventUsers = [
			$user->getName() => true,
		];
		$this->provider = $neverProvider;
		$this->mergeMwGlobalArrayValue( 'wgHooks', [ 'SessionMetadata' => [ $neverHook ] ] );
		$this->store->setSessionData( self::SESSIONID, $testData );
		$backend = $this->getBackend( $user );
		$this->store->deleteSession( self::SESSIONID );
		\TestingAccessWrapper::newFromObject( $backend )->persist = true;
		$this->assertTrue( $backend->isPersistent(), 'sanity check' );
		\TestingAccessWrapper::newFromObject( $backend )->metaDirty = true;
		\TestingAccessWrapper::newFromObject( $backend )->dataDirty = true;
		$backend->save();
		$this->assertFalse( $this->store->getSession( self::SESSIONID ), 'making sure it didn\'t save' );
	}

	public function testRenew() {
		$user = User::newFromName( 'UTSysop' );
		$this->store = new TestBagOStuff();
		$testData = [ 'foo' => 'foo!', 'bar', [ 'baz', null ] ];

		// Not persistent
		$this->provider = $this->getMock( 'DummySessionProvider', [ 'persistSession' ] );
		$this->provider->expects( $this->never() )->method( 'persistSession' );
		$this->onSessionMetadataCalled = false;
		$this->mergeMwGlobalArrayValue( 'wgHooks', [ 'SessionMetadata' => [ $this ] ] );
		$this->store->setSessionData( self::SESSIONID, $testData );
		$backend = $this->getBackend( $user );
		$this->store->deleteSession( self::SESSIONID );
		$wrap = \TestingAccessWrapper::newFromObject( $backend );
		$this->assertFalse( $backend->isPersistent(), 'sanity check' );
		$wrap->metaDirty = false;
		$wrap->dataDirty = false;
		$wrap->forcePersist = false;
		$wrap->expires = 0;
		$backend->renew();
		$this->assertTrue( $this->onSessionMetadataCalled );
		$blob = $this->store->getSession( self::SESSIONID );
		$this->assertInternalType( 'array', $blob );
		$this->assertArrayHasKey( 'metadata', $blob );
		$metadata = $blob['metadata'];
		$this->assertInternalType( 'array', $metadata );
		$this->assertArrayHasKey( '???', $metadata );
		$this->assertSame( '!!!', $metadata['???'] );
		$this->assertNotEquals( 0, $wrap->expires );

		// Persistent
		$this->provider = $this->getMock( 'DummySessionProvider', [ 'persistSession' ] );
		$this->provider->expects( $this->atLeastOnce() )->method( 'persistSession' );
		$this->onSessionMetadataCalled = false;
		$this->mergeMwGlobalArrayValue( 'wgHooks', [ 'SessionMetadata' => [ $this ] ] );
		$this->store->setSessionData( self::SESSIONID, $testData );
		$backend = $this->getBackend( $user );
		$this->store->deleteSession( self::SESSIONID );
		$wrap = \TestingAccessWrapper::newFromObject( $backend );
		$wrap->persist = true;
		$this->assertTrue( $backend->isPersistent(), 'sanity check' );
		$wrap->metaDirty = false;
		$wrap->dataDirty = false;
		$wrap->forcePersist = false;
		$wrap->expires = 0;
		$backend->renew();
		$this->assertTrue( $this->onSessionMetadataCalled );
		$blob = $this->store->getSession( self::SESSIONID );
		$this->assertInternalType( 'array', $blob );
		$this->assertArrayHasKey( 'metadata', $blob );
		$metadata = $blob['metadata'];
		$this->assertInternalType( 'array', $metadata );
		$this->assertArrayHasKey( '???', $metadata );
		$this->assertSame( '!!!', $metadata['???'] );
		$this->assertNotEquals( 0, $wrap->expires );

		// Not persistent, not expiring
		$this->provider = $this->getMock( 'DummySessionProvider', [ 'persistSession' ] );
		$this->provider->expects( $this->never() )->method( 'persistSession' );
		$this->onSessionMetadataCalled = false;
		$this->mergeMwGlobalArrayValue( 'wgHooks', [ 'SessionMetadata' => [ $this ] ] );
		$this->store->setSessionData( self::SESSIONID, $testData );
		$backend = $this->getBackend( $user );
		$this->store->deleteSession( self::SESSIONID );
		$wrap = \TestingAccessWrapper::newFromObject( $backend );
		$this->assertFalse( $backend->isPersistent(), 'sanity check' );
		$wrap->metaDirty = false;
		$wrap->dataDirty = false;
		$wrap->forcePersist = false;
		$expires = time() + $wrap->lifetime + 100;
		$wrap->expires = $expires;
		$backend->renew();
		$this->assertFalse( $this->onSessionMetadataCalled );
		$this->assertFalse( $this->store->getSession( self::SESSIONID ), 'making sure it didn\'t save' );
		$this->assertEquals( $expires, $wrap->expires );
	}

	public function onSessionMetadata( SessionBackend $backend, array &$metadata, array $requests ) {
		$this->onSessionMetadataCalled = true;
		$metadata['???'] = '!!!';
	}

	public function testResetIdOfGlobalSession() {
		if ( !PHPSessionHandler::isInstalled() ) {
			PHPSessionHandler::install( SessionManager::singleton() );
		}
		if ( !PHPSessionHandler::isEnabled() ) {
			$rProp = new \ReflectionProperty( 'MediaWiki\\Session\\PHPSessionHandler', 'instance' );
			$rProp->setAccessible( true );
			$handler = \TestingAccessWrapper::newFromObject( $rProp->getValue() );
			$resetHandler = new \ScopedCallback( function () use ( $handler ) {
				session_write_close();
				$handler->enable = false;
			} );
			$handler->enable = true;
		}

		$backend = $this->getBackend( User::newFromName( 'UTSysop' ) );
		\TestingAccessWrapper::newFromObject( $backend )->usePhpSessionHandling = true;

		TestUtils::setSessionManagerSingleton( $this->manager );

		$manager = \TestingAccessWrapper::newFromObject( $this->manager );
		$request = \RequestContext::getMain()->getRequest();
		$manager->globalSession = $backend->getSession( $request );
		$manager->globalSessionRequest = $request;

		session_id( self::SESSIONID );
		\MediaWiki\quietCall( 'session_start' );
		$backend->resetId();
		$this->assertNotEquals( self::SESSIONID, $backend->getId() );
		$this->assertSame( $backend->getId(), session_id() );
		session_write_close();

		session_id( '' );
		$this->assertNotSame( $backend->getId(), session_id(), 'sanity check' );
		$backend->persist();
		$this->assertSame( $backend->getId(), session_id() );
		session_write_close();
	}

	public function testGetAllowedUserRights() {
		$this->provider = $this->getMockBuilder( 'DummySessionProvider' )
			->setMethods( [ 'getAllowedUserRights' ] )
			->getMock();
		$this->provider->expects( $this->any() )->method( 'getAllowedUserRights' )
			->will( $this->returnValue( [ 'foo', 'bar' ] ) );

		$backend = $this->getBackend();
		$this->assertSame( [ 'foo', 'bar' ], $backend->getAllowedUserRights() );
	}

}
