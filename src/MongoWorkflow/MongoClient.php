<?php
namespace MongoWorkflow;

class MongoClient
{

    public $mongo;

    public function __construct()
    {

        $this->mongo = new \Mongo('localhost');
    }

    public function getDb()
    {
        $dbName = 'workflow';

        return $this->mongo->$dbName;
    }
}
