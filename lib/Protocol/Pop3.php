<?php
/**
 * MailKit
 *
 * @package MailKit
 * @author Ryan Cavicchioni <ryan@confabulator.net>
 * @copyright Copyright (c) 2009-2010, Ryan Cavicchioni
 * @license http://www.opensource.org/licenses/bsd-license.php BSD Licnese
 */

namespace Mail\Protocol;

use Mail\Protocol;

/**
 * The class POP3 can be used to access POP3 servers.
 *
 * @package MailKit
 * @author Ryan Cavicchioni <ryan@confabulator.net>
 * @copyright Copyright (c) 2009-2010, Ryan Cavicchioni
 * @license http://www.opensource.org/licenses/bsd-license.php BSD Licnese
 */
class Pop3 extends AbstractProtocol
{

    /**
     * The termination octet marks the end of a multiline response.
     */
    const TERMINATION_OCTET = ".";

    /**
     * The positive status indicator from the server.
     */
    const RESP_OK = "+OK";

    /**
     * The negative status indicator from the server.
     */
    const RESP_ERR = "-ERR";

    // POP3 session states from RFC 1939.

    /**
     * POP3 session state when the client is not connected to the
     * server.
     */
    const STATE_NOT_CONNECTED = 0;

    /**
     * POP3 session state when the client has connected to the server,
     * the server _sends a greeting and the client must identify
     * itself.
     */
    const STATE_AUTHORIZATION = 1;

    /**
     * POP3 session state where the client has authenticated with the
     * server and requests actions on part of the POP3 server.
     */
    const STATE_TRANSACTION = 2;

    /**
     * POP3 state when the client issues a QUIT command. Changes that
     * the client made are not committed to the server.
     */
    const STATE_UPDATE = 4;

    /**
     * The username used to authenticate with the POP3 server.
     *
     * @var string
     * @access private
     */
    private $_username = null;

    /**
     * The password used to authenticate with the POP3 server.
     *
     * @var string
     * @access private
     */
    private $_password = null;

    /**
     * The capabilities of the POP3 server which are populated by the
     * CAPA command.
     *
     * @var array
     */
    private $_capabilities = array();

    /**
     * The current POP3 session state of the server.
     *
     * @var int Use self::STATE_NOT_CONNECTED,
     *              self::STATE_AUTHORIZATION,
     *              self::STATE_TRANSACTION,
     *           OR self::STATE_UPDATE
     * @access protected
     */
    protected $_state = self::STATE_NOT_CONNECTED;

    /**
     * Public constructor.
     *
     * @param array $config
     */
    public function __construct(array $config = array())
    {
        $defaultConfig = array(
          'host'     => 'localhost',
          'port'     => 110,
          'ssl_mode' => 'tcp',
          'timeout'  => 30
        );

        $config = array_merge($defaultConfig, $config);

        parent::__construct($config);
    }

    /**
     * Connect to the POP3 server.
     *
     * @throws Protocol\Exception
     *         if the connection is already established
     *         or if PHP does not have the openssl extension loaded
     *         or if PHP failed to connect to the POP3 server
     *         or if a negative response from the POP3 server was
     *         received.
     */
    public function connect()
    {
        parent::connect();

        $this->_state = self::STATE_AUTHORIZATION;

        if ($this->_transport === 'tls') {
            $this->_starttls();
        }
    }

    /**
     * Retrieve the capabilities of the POP3 server.
     *
     * @param string $format
     * @return array
     */
    public function getServerCapabilities($format = 'array')
    {
        $this->_validateState(self::STATE_AUTHORIZATION | self::STATE_TRANSACTION, 'CAPA');

        $this->_send("CAPA");
        $resp = $this->_getResponse();

        if ($this->_isResponseOk($resp) !== true) {
            throw new Protocol\Exception("The server returned a negative response to the CAPA command: {$resp}.");
        }

        while ($resp = $this->_getResponse()) {
            if ($this->_isTerminationOctet($resp) === true) {
                break;
            }

            $this->_capabilities[] = rtrim($resp);
        }

        if ($format === 'raw') {
            return implode($this->_capabilities, self::CRLF);
        }

        return $this->_capabilities;
    }

    /**
     * Start TLS negotiation on the current connection.
     *
     * Returns true if the TLS connection was successfully
     * established.
     *
     * @throws Protocol\Exception
     *         if the server returned a negative response to the STLS
     *         (STARTTLS) command
     *         or if the TLS negotiation has failed.
     * @return bool
     */
    protected function _starttls()
    {
        $this->_isServerCapable("STLS");

        $this->_validateState(self::STATE_AUTHORIZATION, 'STLS');

        $this->_send("STLS");
        $resp = $this->_getResponse();

        if ($this->_isResponseOk($resp) !== true) {
            throw new Protocol\Exception("The server returned a negative response to the STLS command: {$resp}");
        }

        parent::_starttls();

        return true;
    }

    /**
     * Authenticate the user to the server.
     *
     * @param array $authConfig
     * @throws Protocol\Exception
     *         if an invalid authentication method is used.
     * @return bool
     * @todo Disable insecure authentication.
     */
    public function authenticate(array $authConfig = array())
    {
        $this->_validateState(self::STATE_AUTHORIZATION, 'USER');

        $defaultAuthConfig = array(
          'user'      => 'anonymous',
          'password'  => 'anonymous',
          'mechanism' => 'plain'
        );

        array_merge($defaultAuthConfig, $authConfig);

        $this->_username = $authConfig['user'];
        $this->_password = $authConfig['password'];

        if (strtolower($authConfig['mechanism']) === 'plain') {
            $status = $this->_authPlain();
        }
        elseif (strtolower($authConfig['mechanism']) === 'login') {
            $status = $this->_authLogin();
        }
        else {
            throw new Protocol\Exception("Invalid authentication method.");
        }

        $this->_state = self::STATE_TRANSACTION;

        return $status;
    }

    /**
     * Authenticate using the PLAIN mechanism.
     *
     * @throws Protocol\Exception
     *         if authentication fails.
     * @return bool
     */
    private function _authPlain()
    {
        $this->_send(sprintf("USER %s", $this->_username));
        $resp = $this->_getResponse(true);

        if ($this->_isResponseOk($resp) === false) {
            throw new Protocol\Exception("The username is not valid: {$resp}");
        }

        $this->_send(sprintf("PASS %s", $this->_password));
        $resp = $this->_getResponse(true);

        if ($this->_isResponseOk($resp) === false) {
            throw new Protocol\Exception("The password is not valid: {$resp}");
        }

        return true;
    }

    /**
     * Authenticate using the LOGIN mechanism.
     *
     * @throws Protocol\Exception
     *         if the server returns a negative response
     *         or if authentication fails.
     * @return bool
     */
    private function _authLogin()
    {
        $this->_send("AUTH LOGIN");
        $resp = $this->_getResponse(true);

        if (strpos($resp, "+") === false) {
            throw new Protocol\Exception("The server returned a negative response to the AUTH LOGIN command: {$resp}");
        }

        $this->_send(base64_encode($this->_username));
        $resp = $this->_getResponse(true);

        if (strpos($resp, "+") === false) {
            throw new Protocol\Exception("The username is not valid: {$resp}");
        }

        $this->_send(base64_encode($this->_password));
        $resp = $this->_getResponse(true);

        if ($this->_isResponseOk($resp) === false) {
            throw new Protocol\Exception("The password is not valid: {$resp}");
        }

        return true;
    }

    /**
     t* Issues the STAT command to the server and returns a drop
     * listing.
     *
     * @throws Protocol\Exception
     *         if the server did not respond with a status message.
     * @return array
     */
    public function status()
    {
        $this->_validateState(self::STATE_TRANSACTION, 'STAT');

        $this->_send("STAT");
        $resp = $this->_getResponse();

        if ($this->_isResponseOk($resp) === false) {
            throw new Protocol\Exception("The server did not respond with a status message: {$resp}");
        }

        sscanf($resp, "+OK %d %d", $msgno, $size);
        $maildrop = array('messages' => (int) $msgno, 'size' => (int) $size);

        return $maildrop;
    }

    /**
     * Issues the LIST command to the server and returns a scan
     * listing.
     *
     * @param int $messageId
     * @throws Protocol\Exception
     *         if the server did not respond with a scan listing.
     * @return array
     */
    public function listMessages($messageId = null)
    {
        $this->_validateState(self::STATE_TRANSACTION, 'LIST');

        if ($messageId !== null) {
            $this->_send(sprintf("LIST %s", $messageId));
        }
        else {
            $this->_send("LIST");
        }

        $resp = $this->_getResponse();

        if ($this->_isResponseOk($resp) === false) {
            throw new Protocol\Exception("The server did not respond with a scan listing: {$resp}");
        }

        if ($messageId !== null) {
            sscanf($resp, "+OK %d %s", $id, $size);
            return array('id' => $id, 'size' => $size);
        }

        $messages = null;
        while ($resp = $this->_getResponse()) {
            if ($this->_isTerminationOctet($resp) === true) {
                break;
            }

            list($messageId, $size) = explode(' ', rtrim($resp));
            $messages[(int)$messageId] = (int)$size;
        }

        return $messages;
    }

    /**
     * Issues the RETR command to the server and returns the contents
     * of a message.
     *
     * @param int $messageId
     * @throws Protocol\Exception
     *         if the message id is not defined
     *         or if the server returns a negative response to the
     *         RETR command.
     * @return string
     */
    public function retrieve($messageId)
    {
        $this->_validateState(self::STATE_TRANSACTION, 'RETR');

        if ($messageId === null) {
            throw new Protocol\Exception("A message number is required by the RETR command.");
        }

        $this->_send(sprintf("RETR %s", $messageId));
        $resp = $this->_getResponse();

        if ($this->_isResponseOk($resp) === false) {
            throw new Protocol\Exception("The server sent a negative response to the RETR command: {$resp}");
        }

        $message = null;
        while ($resp = $this->_getResponse()) {
            if ($this->_isTerminationOctet($resp) === true) {
                break;
            }

            $message .= $resp;
        }

        return $message;
    }

    /**
     * Deletes a message from the POP3 server.
     *
     * @param int $messageId
     * @throws Protocol\Exception
     *         if the message id is not defined
     *         or if the returns a negative response to the DELE
     *         command.
     * @return bool
     */
    public function delete($messageId)
    {
        $this->_validateState(self::STATE_TRANSACTION, 'DELE');

        if ($messageId === null) {
            throw new Protocol\Exception("A message number is required by the DELE command.");
        }

        $this->_send(sprintf("DELE %s", $messageId));
        $resp = $this->_getResponse();

        if ($this->_isResponseOk($resp) === false) {
            throw new Protocol\Exception("The server sent a negative response to the DELE command: {$resp}");
        }

        return true;
    }

    /**
     * The POP3 server does nothing, it mearly replies with a positive
     * response.
     *
     * @throws Protocol\Exception
     *         if the server returns a negative response to the NOOP
     *         command.
     * @return bool
     */
    public function noop()
    {
        $this->_validateState(self::STATE_TRANSACTION, 'NOOP');

        $this->_send("NOOP");
        $resp = $this->_getResponse();

        if ($this->_isResponseOk($resp) === false) {
            throw new Protocol\Exception("The server sent a negative response to the NOOP command: {$resp}");
        }

        return true;
    }

    /**
     * Resets the changes made in the POP3 session.
     *
     * @throws Protocol\Exception
     *         if the server returns a negative response to the
     *         RSET command.
     * @return bool
     */
    public function reset()
    {
        $this->_validateState(self::STATE_TRANSACTION, 'RSET');

        $this->_send("RSET");
        $resp = $this->_getResponse();

        if ($this->_isResponseOk($resp) === false) {
            throw new Protocol\Exception("The server sent a negative response to the RSET command: {$resp}");
        }

        return true;
    }

    /**
     * Returns the headers of $messageId if $lines is not given. If $lines
     * if given, the POP3 server will respond with the headers and
     * then the specified number of lines from the message's body.
     *
     * @param int $messageId
     * @param int $lines
     * @throws Protocol\Exception
     *         if the message id is not defined
     *         or if the number of lines is not defined
     *         of if the server returns a negative response to the TOP
     *         command.
     * @return string
     */
    public function top($messageId, $lines = 0)
    {
        $this->_isServerCapable("TOP");

        $this->_validateState(self::STATE_TRANSACTION, 'TOP');

        if ($messageId === null) {
            throw new Protocol\Exception("A message number is required by the TOP command.");
        }

        if ($lines === null) {
            throw new Protocol\Exception("A number of lines is required by the TOP command.");
        }

        $this->_send(sprintf("TOP %s %s", $messageId, $lines));
        $resp = $this->_getResponse();

        if ($this->_isResponseOk($resp) === false) {
            throw new Protocol\Exception("The server sent a negative response to the TOP command: {$resp}");
        }

        $message = null;
        while ($resp = $this->_getResponse()) {
            if ($this->_isTerminationOctet($resp) === true) {
                break;
            }

            $message .= $resp;
        }

        return $message;
    }

    /**
     * Issues the UIDL command to the server and returns a unique-id
     * listing.
     *
     * @param int $messageId
     * @throws Protocol\Exception
     *         if the server returns a negative response to the UIDL
     *         command.
     * @return array
     */
    public function uidl($messageId = null)
    {
        $this->_isServerCapable("UIDL");

        $this->_validateState(self::STATE_TRANSACTION, 'UIDL');

        if ($messageId !== null) {
            $this->_send(sprintf("UIDL %s", $messageId));
        }
        else {
            $this->_send("UIDL");
        }

        $resp = $this->_getResponse();

        if ($this->_isResponseOk($resp) === false) {
            throw new Protocol\Exception("The server did not respond with a scan listing: {$resp}");
        }

        if ($messageId !== null) {
            sscanf($resp, "+OK %d %s", $id, $uid);
            return array('id' => (int) $id, 'uid' => $uid);
        }

        $unique_id = null;
        while ($resp = $this->_getResponse()) {
            if ($this->_isTerminationOctet($resp) === true) {
                break;
            }

            list($messageId, $uid) = explode(' ', rtrim($resp));
            $unique_id[(int)$messageId] = $uid;
        }

        return $unique_id;
    }

    /**
     * Issues the QUIT command to the server and enters the UPDATE
     * state.
     *
     * @throws Protocol\Exception
     *         if the server returns a negative response to the QUIT
     *         command.
     * @return bool
     */
    public function quit()
    {
        $this->_validateState(self::STATE_AUTHORIZATION | self::STATE_TRANSACTION, 'QUIT');

        $this->_state = self::STATE_UPDATE;

        $this->_send("QUIT");
        $resp = $this->_getResponse();

        if ($this->_isResponseOk($resp) === false) {
            throw new Protocol\Exception("The server sent a negative response to the QUIT command: {$resp}");
        }

        $this->close();
        $this->_state = self::STATE_NOT_CONNECTED;

        return true;
    }

    /**
     * Determines if the server issued a positive or negative
     * response.
     *
     * @param string $resp
     * @return bool
     */
    protected function _isResponseOk($resp)
    {
        if (strpos($resp, self::RESP_OK) === 0) {
            return true;
        }

        return false;
    }

    /**
     * Determine if the server greeting is positive or negative.
     *
     * @param string $resp
     * @return bool
     */
    protected function _isGreetingOk($resp)
    {
        return $this->_isResponseOk($resp);
    }

    /**
     * Determine if a multiline response contains the termination
     * octet.
     *
     * @param string $resp
     * @return bool
     */
    private function _isTerminationOctet($resp)
    {
        if (preg_match("/\.\s/",$resp) && strpos(rtrim($resp, self::CRLF), self::TERMINATION_OCTET) === 0 ) {
            return true;
        }

        return false;
    }

    /**
     * Returns the current session state name for exception messages.
     *
     * @return string
     */
    private function _stateToString($state)
    {
        $state_map = array(
            self::STATE_NOT_CONNECTED => 'STATE_NOT_CONNECTED',
            self::STATE_AUTHORIZATION => 'STATE_AUTHORIZATION',
            self::STATE_TRANSACTION   => 'STATE_TRANSACTION',
            self::STATE_UPDATE        => 'STATE_UPDATE'
       );

        return $state_map[$state];
    }

    /**
     * Determines if the server is capable of the given command.
     *
     * @param string $cmd
     * @throws Protocol\Exception
     *         if the server is not capable of the command.
     */
    private function _isServerCapable($cmd)
    {
        if (empty($this->_capabilities) === true) {
            $this->getServerCapabilities();
        }

        if (in_array($cmd, $this->_capabilities) === false) {
            throw new Protocol\Exception("The server does not support the {$cmd} command.");
        }

        return true;
    }

    /**
     * Determines if the current state is valid for the given command.
     *
     * @param int $valid_state
     * @param string $cmd
     * @throws Protocol\Exception
     *         if the command if not valid for the current state.
     */
    protected function _validateState($valid_state, $cmd)
    {
        if (($valid_state & $this->_state) == 0) {
            throw new Protocol\Exception("This {$cmd} command is invalid for the current state: {$this->_stateToString($this->_state)}.");
        }
    }
}
