<?php
//ini_set('display_errors', 1);
//error_reporting(E_ALL);

if (!function_exists('sys_get_temp_dir')) {
	function sys_get_temp_dir() {
		if($temp = getenv('TMP')) { return $temp; }
		else if($temp = getenv('TEMP')) { return $temp; }
		else if($temp = getenv('TMPDIR')) { return $temp; }

		$temp = tempnam(__FILE__, '');

		if (file_exists($temp)) {
			unlink($temp);
			return dirname($temp);
		}
		return "/tmp/";
	}
}

/**
 * Implements threading in PHP
 *
 * @package <none>
 * @version 1.0.0 - stable
 * @author Tudor Barbu <miau@motane.lu>
 * @copyright MIT
 */
class FakeThread {
	const FUNCTION_NOT_CALLABLE     = 10;
	const COULD_NOT_FORK            = 15;

	/**
	 * possible errors
	 *
	 * @var array
	 */
	private $errors = array(
		FakeThread::FUNCTION_NOT_CALLABLE   => 'You must specify a valid function name that can be called from the current scope.',
		FakeThread::COULD_NOT_FORK          => 'pcntl_fork() returned a status of -1. No new process was created',
	);

	/**
	 * callback for the function that should
	 * run as a separate thread
	 *
	 * @var callback
	 */
	protected $runnable;

	/**
	 * holds the current process id
	 *
	 * @var integer
	 */
	private $pid;

	/**
	 * Holes the pipe name.
	 */
	private $pipeName;

	/**
	 * checks if threading is supported by the current
	 * PHP configuration
	 *
	 * @return boolean
	 */
	public static function available() {
		$required_functions = array(
			'pcntl_fork',
		);

		foreach( $required_functions as $function ) {
			if ( !function_exists( $function ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * class constructor - you can pass
	 * the callback function as an argument
	 *
	 * @param callback $_runnable
	 */
	public function __construct( $_runnable = null ) {
		if( $_runnable !== null ) {
				$this->setRunnable( $_runnable );
		}
	}

	/**
	 * sets the callback
	 *
	 * @param callback $_runnable
	 * @return callback
	 */
	public function setRunnable( $_runnable ) {
		if( self::runnableOk( $_runnable ) ) {
			$this->runnable = $_runnable;
		}
		else {
			throw new Exception( $this->getError( FakeThread::FUNCTION_NOT_CALLABLE ), FakeThread::FUNCTION_NOT_CALLABLE );
		}
	}

	/**
	 * gets the callback
	 *
	 * @return callback
	 */
	public function getRunnable() {
		return $this->runnable;
	}

	/**
	 * checks if the callback is ok (the function/method
	 * actually exists and is runnable from the current
	 * context)
	 *
	 * can be called statically
	 *
	 * @param callback $_runnable
	 * @return boolean
	 */
	public static function runnableOk( $_runnable ) {
		return ( function_exists( $_runnable ) && is_callable( $_runnable ) );
	}

	/**
	 * returns the process id (pid) of the simulated thread
	 *
	 * @return int
	 */
	public function getPid() {
		return $this->pid;
	}

	/**
	 * checks if the child thread is alive
	 *
	 * @return boolean
	 */
	public function isAlive() {
		$pid = pcntl_waitpid( $this->pid, $status, WNOHANG );
		return ( $pid === 0 );

	}

	/**
	 * Get data from the pipe.
	 */
	public function getData() {
		$data = file_get_contents($this->pipeName);
		unlink($this->pipeName);
		return unserialize($data);
	}

	/**
	 * starts the thread, all the parameters are
	 * passed to the callback function
	 *
	 * @return void
	 */
	public function start() {
		$this->pipeName = tempnam(sys_get_temp_dir(), 'COMM');
		$pid = @ pcntl_fork();
		if( $pid == -1 ) {
			throw new Exception( $this->getError( FakeThread::COULD_NOT_FORK ), FakeThread::COULD_NOT_FORK );
		}
		if( $pid ) {
			// parent
			$this->pid = $pid;
		}
		else {
			// child
			pcntl_signal( SIGTERM, array( $this, 'signalHandler' ) );
			$arguments = func_get_args();

			if ( !empty( $arguments ) ) {
				$result = call_user_func_array( $this->runnable, $arguments );
			}
			else {
				$result = call_user_func( $this->runnable );
			}

			file_put_contents($this->pipeName, serialize($result));

			exit( 0 );
		}
	}

	/**
	 * attempts to stop the thread
	 * returns true on success and false otherwise
	 *
	 * @param integer $_signal - SIGKILL/SIGTERM
	 * @param boolean $_wait
	 */
	public function stop( $_signal = SIGKILL, $_wait = false ) {
		if( $this->isAlive() ) {
			posix_kill( $this->pid, $_signal );
			if( $_wait ) {
				pcntl_waitpid( $this->pid, $status = 0 );
			}
		}
	}

	/**
	 * alias of stop();
	 *
	 * @return boolean
	 */
	public function kill( $_signal = SIGKILL, $_wait = false ) {
		return $this->stop( $_signal, $_wait );
	}

	/**
	 * gets the error's message based on
	 * its id
	 *
	 * @param integer $_code
	 * @return string
	 */
	public function getError( $_code ) {
		if ( isset( $this->errors[$_code] ) ) {
			return $this->errors[$_code];
		}
		else {
			return 'No such error code ' . $_code . '! Quit inventing errors!!!';
		}
	}

	/**
	 * signal handler
	 *
	 * @param integer $_signal
	 */
	protected function signalHandler( $_signal ) {
		switch( $_signal ) {
			case SIGTERM:
				exit( 0 );
			break;
		}
	}
}

// EOF
