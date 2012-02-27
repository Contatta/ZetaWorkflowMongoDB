<?php

namespace MongoWorkflow;

class DefinitionStorage implements \ezcWorkflowDefinitionStorage
{

    protected $client;

    public function __construct($client)
    {

        $this->mongoClient = $client;
    }
    public function loadById($workflowId)
    {

        $doc = null;
        $doc = $this->mongoClient->getDb()->workflow->findOne(array('_id' => $workflowId));


        return $this->loadWorkflowFromDocument($doc);
    }

    public function loadByName($workflowName, $workflowVersion = 0)
        {
            if ($workflowVersion == 0) {
                $params = array('key' => array($workflowName), 'descending' => true, 'limit' => 1);
            } else {
                $params = array('key' => array($workflowName, $workflowVersion));
            }

            //TODO cope with versions
            $doc = $this->mongoClient->getDb()->workflow->findOne(
                array('name' => $workflowName));
            /**
            $body = $this->client->request('GET', "/_design/workflow/_view/by-name-version", null, $params);

            if ($body['total_rows'] != 1) {
                throw new RuntimeException("Only exactly 1 result was expected, but " . $body['total_rows'] . " found!");
            }
            */
            return $this->loadWorkflowFromDocument($doc);
        }


    public function save(\ezcWorkflow $workflow)
    {

        $db = $this->mongoClient->getDb();


       // Verify the workflow.
       $workflow->verify();

       $data = array();
       $data['version'] = microtime(true) - 1280354400; // make the number initially smaller!
       $data['name'] = $workflow->name;
       $data['type'] = 'zeta_workflow';
       $data['created'] = time();
       $data['nodes'] = array();

       $this->nodeIds = array();
       $this->nodeIdCounter = 0;
       foreach ($workflow->nodes AS $node) {
           $nodeId = $this->getNodeId($node);

           $outgoingNodeIds = array();
           foreach ($node->getOutNodes() AS $outNode) {
               $outgoingNodeIds[] = $this->getNodeId($outNode);
           }

           $configData  = array();

           $config = $node->getConfiguration();
           if (is_array($config)) {
               foreach ($config AS $key => $var) {
                   if (is_object($var)) {
                       if ($var instanceof \ezcWorkflowCondition) {

                           /**
                           $keyElement = $configXml->createElement($key);
                           $configXml->documentElement->appendChild($keyElement);
                           $keyElement->appendChild(\ezcWorkflowDefinitionStorageXml::conditionToXml($var, $configXml));

                           unset($config[$key]);
                           */
                       } else {
                           // ieeks objects :-)
                           $config[$key] = serialize($var);
                       }
                   } else if ($key == 'condition' && is_array($var)) {
                       // this is a special case for all the ezcWorkflowNodeBranch implementations. It has to be handled
                       // as special case for deerializing also.
                       /**
                       $keyElement = $configXml->createElement('conditionArray');
                       $configXml->documentElement->appendChild($keyElement);
                       foreach ($var AS $_key => $condition) {
                           $conditionKeyElement = $configXml->createElement("outNode");
                           $conditionKeyElement->setAttribute('key', $_key);
                           $keyElement->appendChild($conditionKeyElement);
                           $conditionKeyElement->appendChild(\ezcWorkflowDefinitionStorageXml::conditionToXml($condition, $configXml));
                       }
                       unset($config[$key]);
                       */
                   }
               }
           }

           $nodeData = array(
               'class' => get_class($node),
               'configuration' => $config,
               'outgoingNodes' => $outgoingNodeIds,
           );

           /**
           // save configuration xml if necessary!
           if ($configXml->documentElement->hasChildNodes()) {
               $nodeData['configurationXml'] = $configXml->saveXML();
           }
           */
           $data['nodes'][$nodeId] = $nodeData;
       }

       foreach ($workflow->getVariableHandlers() as $variable => $class) {
           if (!isset($data['variableHandles'])) {
               $data['variableHandlers'] = array();
           }
           $data['variableHandlers'][$variable] = $class;
       }


       $db->workflow->insert($data);


       if (isset($doc['_id'])) {
           $workflow->id = $doc['_id'];
           $workflow->version = $doc['rev'];
       } else {
           // TODO: something
       }

       return $workflow;

    }


    protected function loadWorkflowFromDocument($doc)
    {
        $nodes = array();
                $startNode = null;
                $defaultEndNode = null;
                $finallyNode = null;
                $connections = array();

                if (!isset($doc['_id']) || !isset($doc['_rev'])) {
                    //throw CouchWorkflow_InvalidWorkflowException::missingIdAndRevision();
                }

                if (!isset($doc['type']) || $doc['type'] != "zeta_workflow") {
                    //throw CouchWorkflow_InvalidWorkflowException::invalidDocumentType($doc['id']);
                }

                foreach ($doc['nodes'] AS $id => $data) {

                    $configuration = $data['configuration'];

                    if ($configuration === null)
                    {
                        $configuration = \ezcWorkflowUtil::getDefaultConfiguration( $data['class'] );
                    }

                    // If there is a configuration xml node we have to deserialize it correctly
                    /**
                    if (isset($data['configurationXml'])) {
                        $dom = new DOMDocument('1.0', 'UTF-8');
                        $dom->loadXML($data['configurationXml']);

                        foreach ($dom->documentElement->childNodes AS $childNode) {
                            if ($childNode->nodeName == "conditionArray") {
                                $configuration['condition'] = array();
                                foreach ($childNode->childNodes AS $conditionChildNode) {
                                    $key = $conditionChildNode->attributes->getNamedItem('key')->value;
                                    $condition = ezcWorkflowDefinitionStorageXml::xmlToCondition($conditionChildNode->childNodes->item(0));
                                    $configuration['condition'][$key] = $condition;
                                }
                            } else {
                                $condition = ezcWorkflowDefinitionStorageXml::xmlToCondition($childNode->childNodes->item(0));
                                $configuration[$childNode->nodeName] = $condition;
                            }
                        }
                    }
                    */
                    $nodes[$id] = new $data['class']($configuration);

                    if ($nodes[$id] instanceof \ezcWorkflowNodeFinally && $finallyNode === null )
                    {
                        $finallyNode = $nodes[$id];
                    }
                    else if ($nodes[$id] instanceof \ezcWorkflowNodeEnd && $defaultEndNode === null)
                    {
                        $defaultEndNode = $nodes[$id];
                    }
                    else if ($nodes[$id] instanceof \ezcWorkflowNodeStart && $startNode === null)
                    {
                       $startNode = $nodes[$id];
                    }

                    foreach ($data['outgoingNodes'] AS $outgoingNodeId) {
                        $connections[] = array('incoming_node_id' => $id, 'outgoing_node_id' => $outgoingNodeId);
                    }
                }

                if ( !isset( $startNode ) || !isset( $defaultEndNode ) )
                {
                    throw new \ezcWorkflowDefinitionStorageException(
                      'Could not load workflow definition.'
                    );
                }

                foreach ( $connections as $connection )
                {
                    $nodes[$connection['incoming_node_id']]->addOutNode(
                      $nodes[$connection['outgoing_node_id']]
                    );
                }

                $workflow = new Workflow($doc['name'], $startNode, $defaultEndNode, $finallyNode);
                $workflow->definitionStorage = $this;
                $workflow->id = $doc['_id'];
                //$workflow->version = $doc['version'];
                $workflow->version = 1; //TODO

                if (isset($doc['variableHandlers']) && is_array($doc['variableHandlers'])) {
                    foreach ($doc['variableHandlers'] AS $varName => $handler) {
                        $workflow->addVariableHandler($varName, $handler);
                    }
                }

                // Verify the loaded workflow.
                $workflow->verify();

                return $workflow;
    }

    private function getNodeId($node)
    {
        $hash = spl_object_hash($node);
        if (!isset($this->nodeIds[$hash])) {
            $this->nodeIds[$hash] = ++$this->nodeIdCounter;
        }

        return $this->nodeIds[$hash];
    }
}


