<?php

require_once "HTTP/Request2.php";
require_once "sesame_resultFormats.php";

/**
 * This Class is an interface to Sesame. It connects and perform queries through http.
 *
 * This class requires Sesame running in a servlet container (tested on tomcat).
 * The class does not perform all the operation implemented in the sesame http api but
 * does the minimal work needed for hyperJournal. You can find more details about Sesame
 * and its HTTP API at www.openrdf.org
 *
 * Based on the phesame library, http://www.hjournal.org/phesame/
 *
 * @author Alex Latchford * 
 * @version 0.1
 * @author Simon Martin
 */
class Sesame
{
	// Return MIME types
	const SPARQL_XML = 'application/sparql-results+xml';
	const SPARQL_JSON = 'application/sparql-results+json';			// Unsupported - No results handler
	const BINARY_TABLE = 'application/x-binary-rdf-results-table';	// Unsupported - No results handler
	const BOOLEAN = 'text/boolean';									// Unsupported - No results handler

	// Input MIME Types
	const RDFXML = 'application/rdf+xml';
	const NTRIPLES = 'text/plain';
	const TURTLE = 'application/x-turtle';
	const N3 = 'text/rdf+n3';
	const TRIX = 'application/trix';
	const TRIG = 'application/x-trig';	

	
	private $_sesameUrl;
	private $_repository;
	private $_queryLang;
	private $_infer;
	private $_namespaces;	

	/**
	 * 
	 *
	 * @param	string	$sesameUrl		Sesame server connection string
	 * @param	string	$repository		The repository name
	 */
	function __construct($sesameUrl = 'http://localhost:8080/openrdf-sesame', $repository = null)
	{
		$this->_sesameUrl = $sesameUrl;
		$this->setRepository($repository);
		$this->setQueryLang('sparql');
		$this->setInfer(true);
		$this->_namespaces = array();
	}

	/**
	 * Select a repository to work on
	 *
	 * @return	void
	 * @param	string	$rep	The repository name
	 */
	public function setRepository($rep){
		$this->_repository = $rep;
	}
	
	public function setQueryLang($queryLang){
		$this->_queryLang = $queryLang;
	}
	
	public function setInfer($infer){
		$this->_infer = $infer;
	}
	
	private function checkRepository(){
		if (empty($this->_repository) || $this->_repository == '')
		{
			throw new Exception ('No repository has been selected.');
		}
	}

	private function checkQueryLang(){
		if ($this->_queryLang != 'sparql' && $this->_queryLang != 'serql')
		{
			throw new Exception ('Please supply a valid query language, SPARQL or SeRQL supported.');
		}
	}

	/**
	 * @todo	Add in the other potentially supported formats once handlers have been written.
	 *
	 * @param	string	$format
	 */
	private function checkResultFormat($format)	{
		if ($format != self::SPARQL_XML)
		{
			throw new Exception ('Please supply a valid result format.');
		}
	}

	/**
	 * 
	 *
	 * @param	string	&$context
	 */
	private function checkContext(&$context){
		if($context != 'null')
		{
			$context = (substr($context, 0, 1) != '<' || substr($context, strlen($context) - 1, 1) != '>') ? "<$context>" : $context;
			$context = urlencode($context);
		}
	}

	private function checkInputFormat($format){
		if ($format != self::RDFXML && $format != self::N3 && $format != self::NTRIPLES
				&& $format != self::TRIG && $format != self::TRIX && $format != self::TURTLE)
		{
			throw new Exception ('Please supply a valid input format.');
		}
	}
	
	/**
	 * Performs a simple Query.
	 *
	 * Performs a query and returns the result in the selected format. Throws an
	 * exception if the query returns an error. 
	 *
	 * @param	string	$query			String used for query
	 * @param	string	$resultFormat	Returned result format, see const definitions for supported list.
	 * @param	string	$queryLang		Language used for querying, SPARQL and SeRQL supported
	 * @param	bool	$infer			Use inference in the query
	 *
	 * @return	phpSesame_SparqlRes
	 */
	public function query($query, $resultFormat = self::SPARQL_XML)
	{
		$this->checkRepository();
		$this->checkQueryLang();
		$this->checkResultFormat($resultFormat);

		$req = "";
		if(!empty($this->_namespaces)){
			foreach($this->_namespaces as $prefix=>$uri){
				$req .= "PREFIX $prefix:<$uri> ";
			}
		}
		$req .= $query;
		
				
		$request = new HTTP_Request2($this->_sesameUrl . '/repositories/' . $this->_repository, HTTP_Request2::METHOD_POST);
		//$request->setHeader('Content-type: application/sparql-results+xml; charset=utf-8');		
		$request->setHeader('Accept: ' . self::SPARQL_XML);
		$request->setHeader('Accept-Charset', 'utf-8');		
		$request->addPostParameter('query', $req);
		$request->addPostParameter('queryLn', $this->_queryLang);
		$request->addPostParameter('infer', $this->_infer);
		
		$response = $request->send();		
		
		if($response->getStatus() != 200)
		{
			throw new Exception ('Failed to run query, HTTP response error: ' . $response->getStatus());
		}

		return new phpSesame_SparqlRes($response->getBody());
	}
	
	public function queryUpdate($query, $resultFormat = self::SPARQL_XML)
	{
		$this->checkRepository();
		$this->checkQueryLang();
		$this->checkResultFormat($resultFormat);

		$req = "";
		if(!empty($this->_namespaces)){
			foreach($this->_namespaces as $prefix=>$uri){				
				$req .= ("PREFIX $prefix: <$uri> ");				
			}
		}
		$req .= $query;
						
		$request = new HTTP_Request2($this->_sesameUrl . '/repositories/' . $this->_repository. '/statements', HTTP_Request2::METHOD_POST);
		
		$request->setHeader('Accept: ' . self::SPARQL_XML);
		$request->setHeader('Accept-Charset', 'utf-8');	
		$request->addPostParameter('update', $req);
		$request->addPostParameter('queryLn', $this->_queryLang);
		$request->addPostParameter('infer', $this->_infer);

		//var_dump($request); exit;
		$response = $request->send();
		
		//PAS DE CONTENU
		if($response->getStatus() == 204){
				return null;
		}
			
		if($response->getStatus() != 200)
		{			
			throw new Exception ('Failed to run query, HTTP response error: ' . $response->getStatus());
		}			

		return new phpSesame_SparqlRes($response->getBody());
	}

	/**
	 * Appends data to the selected repository
	 *
	 *
	 *
	 * @param	string	$data			Data in the supplied format
	 * @param	string	$context		The context the query should be run against
	 * @param	string	$inputFormat	See class const definitions for supported formats.
	 */
	public function append($data, $context = 'null', $inputFormat = self::RDFXML)
	{
		$this->checkRepository();
		$this->checkContext($context);
		$this->checkInputFormat($inputFormat);
		
		$request = new HTTP_Request2($this->_sesameUrl . '/repositories/' . $this->repository . '/statements?context=' . $context, HTTP_Request2::METHOD_POST);
		$request->setHeader('Content-type: ' . $inputFormat);
		$request->setBody($data);

		$response = $request->send();
		if($response->getStatus() != 204)
		{
			throw new Exception ('Failed to append data to the repository, HTTP response error: ' . $response->getStatus());
		}
	}

	/**
	 * Appends data to the selected repository
	 *
	 *
	 *
	 * @param	string	$filePath		The filepath of data, can be a URL
	 * @param	string	$context		The context the query should be run against
	 * @param	string	$inputFormat	See class const definitions for supported formats.
	 */
	public function appendFile($filePath, $context = 'null', $inputFormat = self::RDFXML)
	{
		$data = $this->getFile($filePath);
		$this->append($data, $context, $inputFormat);
	}

	/**
	 * Overwrites data in the selected repository, can optionally take a context parameter
	 *
	 * @param	string	$data			Data in the supplied format
	 * @param	string	$context		The context the query should be run against
	 * @param	string	$inputFormat	See class const definitions for supported formats.
	 */
	public function overwrite($data, $context = 'null', $inputFormat = self::RDFXML)
	{
		$this->checkRepository();
		$this->checkContext($context);
		$this->checkInputFormat($inputFormat);

		$request = new HTTP_Request2($this->_sesameUrl . '/repositories/' . $this->repository . '/statements?context=' . $context, HTTP_Request2::METHOD_PUT);
		$request->setHeader('Content-type: ' . $inputFormat);
		$request->setBody($data);

		$response = $request->send();
		if($response->getStatus() != 204)
		{
			throw new Exception ('Failed to append data to the repository, HTTP response error: ' . $response->getStatus());
		}
	}

	/**
	 * Overwrites data in the selected repository, can optionally take a context parameter
	 *
	 * @param	string	$filePath		The filepath of data, can be a URL
	 * @param	string	$context		The context the query should be run against
	 * @param	string	$inputFormat	See class const definitions for supported formats.
	 */
	public function overwriteFile($filePath, $context = 'null', $inputFormat = self::RDFXML)
	{
		$data = $this->getFile($filePath);
		$this->overwrite($data, $context, $inputFormat);
	}

	/**
	 * @param	string	$filePath	The filepath of data, can be a URL
	 * @return	string
	 */
	private function getFile($filePath)
	{
		if(empty($filePath) || $filePath == '')
		{
			throw new Exception('Please supply a filepath.');
		}

		return file_get_contents($filePath);
	}
	
	/**
	 * Sets the the namespace for the specified prefix
	 *
	 * @param	string	$prefix			Data in the supplied format
	 * @param	string	$namespace		The context the query should be run against
	 */
	public function addNamespace($prefix, $namespace)
	{
		$this->checkRepository();

		if(empty($prefix) || empty($namespace))
		{
			throw new Exception('Please supply both a prefix and a namespace!');
		}
		
		$this->_namespaces[$prefix] = $namespace;
	}
			
	/**
	 * Returns a list of all the contexts in the repository.
	 *
	 * @param	string	$resultFormat	Returned result format, see const definitions for supported list.
	 *
	 * @return	phpSesame_SparqlRes
	 */
	public function contexts($resultFormat = self::SPARQL_XML)
	{
		$this->checkRepository();
		$this->checkResultFormat($resultFormat);

		$request = new HTTP_Request2($this->_sesameUrl . '/repositories/' . $this->repository . '/contexts', HTTP_Request2::METHOD_POST);
		$request->setHeader('Accept: ' . self::SPARQL_XML);

		$response = $request->send();
		if($response->getStatus() != 200)
		{
			throw new Exception ('Failed to run query, HTTP response error: ' . $response->getStatus());
		}

		return new phpSesame_SparqlRes($response->getBody());
	}

	/**
	 * Returns the size of the repository
	 *
	 * @param	string	$context		The context the query should be run against
	 *
	 * @return	int
	 */
	public function size($context = 'null')
	{
		$this->checkRepository();

		$request = new HTTP_Request2($this->_sesameUrl . '/repositories/' . $this->repository . '/size?context=' . $context, HTTP_Request2::METHOD_POST);
		$request->setHeader('Accept: text/plain');

		$response = $request->send();
		if($response->getStatus() != 200)
		{
			throw new Exception ('Failed to run query, HTTP response error: ' . $response->getStatus());
		}

		return (int) $response->getBody();
	}

	/**
	 * Clears the repository
	 *
	 * Removes all data from the selected repository from ALL contexts.
	 *
	 * @return	void
	 */
	public function clear()
	{
	    $this->checkRepository();
		
		$request = new HTTP_Request2($this->_sesameUrl . '/repositories/' . $this->repository . '/statements', HTTP_Request2::METHOD_DELETE);

		$response = $request->send();
		if($response->getStatus() != 204)
		{
			throw new Exception ('Failed to clear repository, HTTP response error: ' . $response->getStatus());
		}
	}
	
	/**
	 * Gets a list of all the available repositories on the Sesame installation
	 *
	 * @return	phpSesame_SparqlRes
	 */
	public function listRepositories()
	{
		$request = new HTTP_Request2($this->_sesameUrl . '/repositories', HTTP_Request2::METHOD_GET);
		$request->setHeader('Accept: ' . self::SPARQL_XML);

		$response = $request->send();
		if($response->getStatus() != 200)
		{
			throw new Exception ('Phesame engine response error');
		}
	
		return new phpSesame_SparqlRes($response->getBody());
	}
}
?>
