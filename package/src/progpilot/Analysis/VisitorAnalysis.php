<?php

/*
 * This file is part of ProgPilot, a static analyzer for security
 *
 * @copyright 2017 Eric Therond. All rights reserved
 * @license MIT See LICENSE at the root of the project for more info
 */


namespace progpilot\Analysis;

use function DeepCopy\deep_copy;

use progpilot\Objects\MyFile;
use progpilot\Objects\MyOp;
use progpilot\Objects\ArrayStatic;
use progpilot\Objects\MyDefinition;
use progpilot\Dataflow\Definitions;
use progpilot\Objects\MyClass;
use progpilot\Objects\MyFunction;
use progpilot\Objects\MyExpr;

use progpilot\Code\MyCode;
use progpilot\Code\Opcodes;
use progpilot\Code\MyInstruction;

use progpilot\Helpers\Analysis as HelpersAnalysis;

use progpilot\Lang;
use progpilot\Utils;
use progpilot\Analyzer;

class VisitorAnalysis
{
    private $context;
    private $currentStorageMyBlocks;
    private $myBlockStack;

    private $defs;
    private $blocks;

    private $currentMyFunc;
    private $currentContextCall;
    private $currentMyBlock;

    public function __construct()
    {
        $this->currentStorageMyBlocks = null;
        $this->myBlockStack = [];

        $this->currentMyFunc = null;
        $this->currentContextCall = null;
        $this->currentMyBlock = null;

        $this->defs = null;
        $this->blocks = null;
    }

    public function funcCall(
        $myCode,
        $instruction,
        $code,
        $index,
        $funcName,
        $arrFuncCall,
        $myFuncCall
    ) {
        $hasSources = false;
                 
        $listMyFunc = [];

        echo "FUNC_CALL name '$funcName' 3\n";
        IncludeAnalysis::funccall(
            $this->context,
            $this->defs,
            $this->blocks,
            $instruction,
            $code,
            $index
        );

        echo "FUNC_CALL name '$funcName' 4\n";
        $stackClass = null;
        if ($myFuncCall->isType(MyFunction::TYPE_FUNC_METHOD)) {
            echo "visitoranalysis funccall 1 '$funcName' line = '".$myFuncCall->getLine()."'\n";
            
            $stackClass = ResolveDefs::funccallClass(
                $this->context,
                $this->defs->getOutMinusKill($myFuncCall->getBlockId()),
                $myFuncCall,
                $code,
                $index
            );

            $classOfFuncCallArr = $stackClass[0];

            foreach ($classOfFuncCallArr as $classOfFuncCall) {
                $classOfFuncCall->printStdout();

                $objectId = $classOfFuncCall->getCurrentState()->getObjectId();
                $myClass = $this->context->getObjects()->getMyClassFromObject($objectId);

                echo "visitoranalysis funccall 2 '$funcName' '$objectId'\n";
                if (!is_null($myClass)) {
                    $visibility = true;
                    $method = $myClass->getMethod($funcName);
                    
                    echo "visitoranalysis funccall 3 '$funcName'\n";
                    if (!ResolveDefs::getVisibilityMethod($myFuncCall->getNameInstance(), $method)) {
                        $method = null;
                        $visibility = false;
                    }

                    if (!is_null($method)) {
                        echo "visitoranalysis funccall 4 '$funcName' objectid = '$objectId'\n";
                        // we assign the object of the instance to this->
                        $method->getThisDef()->getCurrentState()->setObjectId($objectId);
                    }

                    // twig analysis
                    if ($this->context->inputs->isLanguage(Analyzer::JS)) {
                        if (!is_null($myClass) && $myClass->getName() === "Twig_Environment") {
                            if ($funcName === "render") {
                                TwigAnalysis::funccall($this->context, $myFuncCall, $instruction);
                            }
                        }
                    }
                                    
                    $listMyFunc[] = [$objectId, $myClass, $method, $visibility];

                    $hasSources = TaintAnalysis::funccallSpecifyAnalysis(
                        $method,
                        $stackClass,
                        $this->context,
                        $this->defs->getOutMinusKill($myFuncCall->getBlockId()),
                        $myClass,
                        $myFuncCall,
                        $arrFuncCall,
                        $instruction,
                        $myCode,
                        $index
                    );
                } else {
                    $hasSources = TaintAnalysis::funccallSpecifyAnalysis(
                        null,
                        $stackClass,
                        $this->context,
                        $this->defs->getOutMinusKill($myFuncCall->getBlockId()),
                        null,
                        $myFuncCall,
                        $arrFuncCall,
                        $instruction,
                        $myCode,
                        $index
                    );
                }
            }

            // we didn't resolve any class so the class of method is unknown (undefined)
            // but we authorize to specify method of unknown class during the configuration of sinks ...
            if (count($classOfFuncCallArr) === 0) {
                $hasSources = TaintAnalysis::funccallSpecifyAnalysis(
                    null,
                    $stackClass,
                    $this->context,
                    $this->defs->getOutMinusKill($myFuncCall->getBlockId()),
                    null,
                    $myFuncCall,
                    $arrFuncCall,
                    $instruction,
                    $myCode,
                    $index
                );
            }
        } elseif ($myFuncCall->isType(MyFunction::TYPE_FUNC_STATIC)) {
            $myClassStatic = $this->context->getClasses()->getMyClass(
                $myFuncCall->getNameInstance()
            );

            if (!is_null($myClassStatic)) {
                $visibility = true;
                $method = $myClassStatic->getMethod($funcName);

                if (!ResolveDefs::getVisibilityMethod(
                    $myFuncCall->getNameInstance(),
                    $method
                )) {
                    $method = null;
                    $visibility = false;
                }

                $listMyFunc[] = [0, $myClassStatic, $method, $visibility];

                $myDefStatic = new MyDefinition(
                    $this->context->getCurrentBlock()->getId(),
                    $this->context->getCurrentMyFile(),
                    $myFuncCall->getLine(),
                    $myFuncCall->getColumn(),
                    "static"
                );

                $idObjectTmp = $this->context->getObjects()->addObject();
                $myDefStatic->setObjectId($idObjectTmp);
                $this->context->getObjects()->addMyclassToObject($idObjectTmp, $myClassStatic);
                                
                $stackClass[0][0] = $myDefStatic;

                $hasSources = TaintAnalysis::funccallSpecifyAnalysis(
                    $method,
                    $stackClass,
                    $this->context,
                    $this->defs->getOutMinusKill($myFuncCall->getBlockId()),
                    $myClassStatic,
                    $myFuncCall,
                    $arrFuncCall,
                    $instruction,
                    $myCode,
                    $index
                );
            }
        } else {
            $myFunc = $this->context->getFunctions()->getFunction($funcName);
            // needed?? because below it's also called
            $hasSources = TaintAnalysis::funccallSpecifyAnalysis(
                $myFunc,
                null,
                $this->context,
                $this->defs->getOutMinusKill($myFuncCall->getBlockId()),
                null,
                $myFuncCall,
                $arrFuncCall,
                $instruction,
                $myCode,
                $index
            );

            $listMyFunc[] = [0, null, $myFunc, true];
        }
        
        \progpilot\Analysis\CustomAnalysis::mustVerifyDefinition(
            $this->context,
            $instruction,
            $myFuncCall,
            $stackClass
        );

        foreach ($listMyFunc as $list) {
            $objectId = $list[0];
            $myClass = $list[1];
            $myFunc = $list[2];
            $visibility = $list[3];

            if (!is_null($myFunc) && !$this->context->inCallStack($myFunc)) {
                // the called function is a method and this method exists in the class
                if (($myFuncCall->isType(MyFunction::TYPE_FUNC_METHOD)
                    || $myFuncCall->isType(MyFunction::TYPE_FUNC_STATIC))
                        && $myFunc->isType(MyFunction::TYPE_FUNC_METHOD)
                            || ((!$myFuncCall->isType(MyFunction::TYPE_FUNC_METHOD)
                                && !$myFuncCall->isType(MyFunction::TYPE_FUNC_STATIC))
                                    && !$myFunc->isType(MyFunction::TYPE_FUNC_METHOD))) {
                    // we don't visit twice function with a long execution time
                    if (HelpersAnalysis::checkIfTimeExecutionIsAcceptable($this->context, $myFunc)
                        // other checks
                        && (HelpersAnalysis::checkIfOneFunctionArgumentIsNew($myFunc, $instruction)
                            || !$myFunc->isVisited()
                                || $myFunc->isType(MyFunction::TYPE_FUNC_METHOD)
                                    || $myFunc->hasGlobalVariables()
                                        || $myFunc->getName() === "{main}")) {
                        // we clean all the param of the function
                        $funcCallBack = "Callbacks::cleanTaintedDef";
                        HelpersAnalysis::forAllDefsOfFunction($funcCallBack, $myFunc);

                        // we propagate the taint to the params
                        FuncAnalysis::funccallBefore(
                            $this->context,
                            $myFunc->getDefs(),
                            $myFunc,
                            $myFuncCall,
                            $instruction,
                            $this->context->getClasses()
                        );
                    
                        // we clean all the param of the function
                        // except return defs see functions21.php test case
                        $funcCallBack = "Callbacks::addDefAsAPastArgument";
                        HelpersAnalysis::forAllArgumentsOfFunction($funcCallBack, $myFunc, $instruction);

                        $myFunc->setIsVisited(true);

                        $myCodefunction = new MyCode;
                        $myCodefunction->setCodes($myFunc->getMyCode()->getCodes());
                        $myCodefunction->setStart(0);
                        $myCodefunction->setEnd(count($myFunc->getMyCode()->getCodes()));

                        $this->analyze($myCodefunction, $myFuncCall);

                        if ($myFunc->hasGlobalVariables()) {
                            // we don't want to visit it a second time, it's an approximation for performance
                            $myFunc->setHasGlobalVariables(false);

                            foreach ($myFunc->getReturnDefs() as $returnDef) {
                                $returnDefCopy = deep_copy($returnDef);
                                $myFunc->addInitialReturnDef($returnDefCopy);
                            }
                        }
                    } else {
                        $funcCallBack = "Callbacks::addAttributesOfInitialReturnDefs";
                        HelpersAnalysis::forAllReturnDefsOfFunction($funcCallBack, $myFunc);
                    }
                }
            }
            
            if (!$hasSources) {
                FuncAnalysis::funccallAfter(
                    $this->context,
                    $this->defs->getOutMinusKill($myFuncCall->getBlockId()),
                    $myFuncCall,
                    $myFunc,
                    $arrFuncCall,
                    $instruction,
                    $code,
                    $index
                );
            }

            $classOfFuncCall = null;
            if (is_null($myFunc)) {
                ResolveDefs::funccallReturnValues(
                    $myFuncCall,
                    $instruction,
                    $myCode,
                    $index
                );

                // representations start
                $this->context->outputs->callgraphAddFuncCall(
                    $this->currentMyFunc,
                    $this->currentMyBlock,
                    $myFuncCall,
                    $myClass
                );
            // representations end
            } else {
                $classOfFuncCall = $myFunc->getMyClass();
                
                // representations start
                foreach ($myFunc->getBlocks() as $myBlock) {
                    $this->context->outputs->callgraphAddChild(
                        $this->currentMyFunc,
                        $this->currentMyBlock,
                        $myBlock
                    );
                    $this->context->outputs->cfgAddEdge(
                        $this->currentMyFunc,
                        $this->currentMyBlock,
                        $myBlock
                    );
                    break;
                }

                $this->context->outputs->callgraphAddFuncCall(
                    $this->currentMyFunc,
                    $this->currentMyBlock,
                    $myFuncCall,
                    $myClass
                );
                // representations end
            }
          
            $hasSources = TaintAnalysis::funccallSpecifyAnalysis(
                $myFunc,
                $stackClass,
                $this->context,
                $this->defs->getOutMinusKill($myFuncCall->getBlockId()),
                $classOfFuncCall,
                $myFuncCall,
                $arrFuncCall,
                $instruction,
                $myCode,
                $index
            );
        }
    }

    public function getMyblock($context)
    {
        $this->context = $context;
    }

    public function setContext($context)
    {
        $this->context = $context;
    }

    public function analyze($myCode, $myFuncCalled = null)
    {
        $startTime = microtime(true);
        $index = $myCode->getStart();
        $code = $myCode->getCodes();
        $currentExpr = null;

        $myCode->printStdout();

        do {
            if (isset($code[$index])) {
                $instruction = $code[$index];

                if ((microtime(true) - $startTime) > $this->context->getMaxFileAnalysisDuration()) {
                    Utils::printWarning($this->context, Lang::MAX_TIME_EXCEEDED);
                    return;
                }

                switch ($instruction->getOpcode()) {
                    case Opcodes::ENTER_BLOCK:
                        $myBlock = $instruction->getProperty(MyInstruction::MYBLOCK);

                        if ($this->currentStorageMyBlocks->contains($myBlock)) {
                            array_pop($this->myBlockStack);

                            if (count($this->myBlockStack) > 0) {
                                $this->currentMyBlock = $this->myBlockStack[count($this->myBlockStack) - 1];
                            }

                            $index = $myBlock->getEndAddressBlock();
                            break;
                        }

                        $this->currentMyBlock = $myBlock;
                        $this->context->setCurrentBlock($myBlock);

                        array_push($this->myBlockStack, $this->currentMyBlock);

                        $this->currentStorageMyBlocks->attach($myBlock);
                        
                        // we remove this parent because it's a loop while(block1) block2
                        // and block1 must be analysis before block2
                        if (!$myBlock->getIsLoop()) {
                            foreach ($myBlock->parents as $blockParent) {
                                if (!$this->currentStorageMyBlocks->contains($blockParent)) {
                                    $addrStart = $blockParent->getStartAddressBlock();
                                    $addrEnd = $blockParent->getEndAddressBlock();

                                    $oldIndexStart = $myCode->getStart();
                                    $oldIndexEnd = $myCode->getEnd();

                                    $myCode->setStart($addrStart);
                                    $myCode->setEnd($addrEnd);

                                    $this->analyze($myCode);

                                    $myCode->setStart($oldIndexStart);
                                    $myCode->setEnd($oldIndexEnd);
                                }
                            }
                        }

                        break;
                    

                    case Opcodes::LEAVE_BLOCK:
                        array_pop($this->myBlockStack);

                        echo "LEAVE_BLOCK currentid = '".$this->currentMyBlock->getId()."'\n";

                        if (count($this->myBlockStack) > 0) {
                            $this->currentMyBlock = $this->myBlockStack[count($this->myBlockStack) - 1];
                            $this->context->setCurrentBlock($this->currentMyBlock);
                            echo "LEAVE_BLOCK newcurrentid = '".$this->currentMyBlock->getId()."'\n";
                        }

                        break;
                    

                    case Opcodes::LEAVE_FUNCTION:
                        $myFunc = $instruction->getProperty(MyInstruction::MYFUNC);

                        $diffTime = microtime(true) - $myFunc->getStartExecutionTime();
                        $myFunc->setLastExecutionTime($diffTime);

                        if ($myFunc->getName() === "{main}") {
                            // free memory
                            unset($myFunc);
                            return;
                        }

                        $this->context->popFromCallStack();

                        echo "LEAVE_FUNCTION name = '".$myFunc->getName()."'\n";

                        $callStack = $this->context->getCallStack();
                        if (!empty($callStack)) {
                            echo "LEAVE_FUNCTION NOTEMPTY 1\n";
                            $lastElement = $callStack[count($callStack) -1];

                            $this->currentContextCall = $lastElement[4];
                            $this->currentStorageMyBlocks = $lastElement[3];
                            $this->defs = $lastElement[2];
                            $this->blocks = $lastElement[1];
                            $this->currentMyFunc = $lastElement[0];
                        }

                        // for the properties data flow
                        $lastBlockIdCalled = $myFunc->getLastBlockId();
                        $lastMyBlockCalled = $myFunc->getBlockById($lastBlockIdCalled);
                        $blockOfCallee = $this->currentMyBlock; // leave block has popped the callee block normally

                        if(!is_null($lastMyBlockCalled)
                            && !is_null($blockOfCallee)) {                        
                            echo "LEAVE_FUNCTION add virtual parent = '".$lastMyBlockCalled->getId()."' to '".$blockOfCallee->getId()."'\n";
                            $lastMyBlockCalled->addVirtualParent($blockOfCallee);
                        }
                        // end

                        break;
                    

                    case Opcodes::ENTER_FUNCTION:
                        $startTime = microtime(true);

                        $this->currentContextCall = new \stdClass;
                        $this->currentContextCall->func_called = $myFuncCalled;
                        $this->currentContextCall->func_callee = $this->currentMyFunc;

                        $this->currentMyFunc = $instruction->getProperty(MyInstruction::MYFUNC);
                        $this->context->setCurrentFunc($this->currentMyFunc);
                        

                        $this->currentStorageMyBlocks = new \SplObjectStorage;
                        $this->defs = $this->currentMyFunc->getDefs();
                        $this->blocks = $this->currentMyFunc->getBlocks();

                        echo "ENTER_FUNCTION name = '".$this->currentMyFunc->getName()."'\n";
                        $val = [
                            $this->currentMyFunc,
                                $this->blocks,
                                    $this->defs,
                                        $this->currentStorageMyBlocks,
                                            $this->currentContextCall];
                                            
                        $this->context->pushToCallStack($val);

                        // for the properties data flow
                        $firstBlockIdCalled = $this->currentMyFunc->getFirstBlockId();
                        $firstMyBlockCalled = $this->currentMyFunc->getBlockById($firstBlockIdCalled);
                        $blockOfCallee = $this->currentMyBlock;

                        if(!is_null($firstMyBlockCalled)
                            && !is_null($blockOfCallee)) {                        
                            echo "ENTER_FUNCTION add virtual parent = '".$blockOfCallee->getId()."' to '".$firstMyBlockCalled->getId()."'\n";
                            $firstMyBlockCalled->addVirtualParent($blockOfCallee);
                        }
                        // end

                        $this->currentMyFunc->setStartExecutionTime(microtime(true));
                        $this->currentMyFunc->setNbExecutions($this->currentMyFunc->getNbExecutions() + 1);

                        break;
                    

                    case Opcodes::DEFINITION:
                        $myDef = $instruction->getProperty(MyInstruction::DEF);
                        break;
                    


                    case Opcodes::START_EXPRESSION:
                        $currentExpr = $instruction->getProperty(MyInstruction::EXPR);
    
                        break;


                    case Opcodes::END_EXPRESSION:
                        $expr = $instruction->getProperty(MyInstruction::EXPR);

                        if ($expr->isAssign()) {
                            $defAssign = $expr->getAssignDef();
                            
                            /*
                             * we have all the resolved defs so maybe when we have two def for one tempdef
                             * that could lead to abuse the compute of embedded chars for example
                             * but it's not because all def have the same name (they have been resolved)
                             * and so same embedded char of tempdef
                             */

                            ValueAnalysis::computeSanitizedValues($defAssign, $expr);
                            ValueAnalysis::computeEmbeddedChars($defAssign, $expr);
                            ValueAnalysis::computeCastValues($defAssign, $expr);
                            ValueAnalysis::computeKnownValues($defAssign, $expr);
                        }

                        break;
                    
                    case Opcodes::CONCAT_LEFT:
                        $leftid = $instruction->getProperty(MyInstruction::LEFTID);
                        $rightid = $instruction->getProperty(MyInstruction::RIGHTID);
                        $resultid = $instruction->getProperty(MyInstruction::RESULTID);
                        $expr = $instruction->getProperty(MyInstruction::EXPR);

                        $leftOpInformation = $this->context->getCurrentFunc()->getOpInformation($leftid);
                        $rightOpInformation = $this->context->getCurrentFunc()->getOpInformation($rightid);

                        $opInformation = [];
                        $opInformation["chained_results"] = [];

                        echo "CONCAT1\n";
                        if (isset($leftOpInformation["chained_results"])) {
                            echo "CONCAT2\n";
                            foreach ($leftOpInformation["chained_results"] as $chainedResult) {
                                $opInformation["chained_results"][] = $chainedResult;
                            }
                        }

                        echo "CONCAT3\n";
                        if (isset($rightOpInformation["chained_results"])) {
                            echo "CONCAT4\n";
                            foreach ($rightOpInformation["chained_results"] as $chainedResult) {
                                $opInformation["chained_results"][] = $chainedResult;
                            }
                        }

                        $this->context->getCurrentFunc()->storeOpInformation($resultid, $opInformation);

                        break;


                    case Opcodes::ARRAYDIM_FETCH:
                        $arrayDim = $instruction->getProperty(MyInstruction::ARRAY_DIM);
                        $originalDef = $instruction->getProperty(MyInstruction::ORIGINAL_DEF);

                        $varid = $instruction->getProperty(MyInstruction::VARID);
                        $resultid = $instruction->getProperty(MyInstruction::RESULTID);
                        $expr = $instruction->getProperty(MyInstruction::EXPR);

                        $opInformation = [];
                        $opInformation["chained_results"] = [];
                        $opInformation["def_assign"] = HelpersAnalysis::getAssignedDefOfPreviousInstruction($code, $index);
                        $opInformation["array_dim"] = $arrayDim;

                        echo "ARRAYDIM_FETCH '$arrayDim' 1\n";

                        // beginning of the chain: $originalDef[0][1]
                        if (!is_null($originalDef)) {
                            echo "ARRAYDIM_FETCH '$arrayDim' 2\n";
                            $originalDef->printStdout();
                            
                            // we get the last definitions
                            $defsFound = ResolveDefs::selectArrays(
                                $this->context,
                                $this->defs->getOutMinusKill($this->currentMyBlock->getId()),
                                $originalDef,
                                $arrayDim
                            );

                            foreach ($defsFound as $defFound) {
                                echo "ARRAYDIM_FETCH '$arrayDim' 22 bis\n";
                                // the element has just been created and right side (!expr)
                                if ($defFound[0]) {
                                    if (!is_null($expr)
                                        && HelpersAnalysis::isASource($this->context, $originalDef, $arrayDim)) {
                                        $defFound[1]->setTainted(true);
                                        //TaintAnalysis::setTainted(true, $originalDef, $defFound[1], $arrayDim);
                                    }
                                }

                                // just for the flow
                                $defFound[1]->original->setDef($originalDef);
                                $defFound[1]->original->setArrayIndexAccessor($arrayDim);
                                $defFound[1]->printStdout();
                                $opInformation["chained_results"][] = $defFound[1];
                            }

                            // could be a built-in array/source
                            if (empty($defsFound)) {
                                // right side
                                if (!is_null($expr)
                                    && HelpersAnalysis::isASource($this->context, $originalDef, $arrayDim)) {
                                    $originalDef->getCurrentState()->setTainted(true);
                                    // just for the flow
                                    $originalDef->original->setDef($originalDef);
                                    $originalDef->original->setArrayIndexAccessor($arrayDim);

                                    $opInformation["chained_results"][] = $originalDef;
                                    echo "ARRAYDIM_FETCH '$arrayDim' 22 bis bis\n";
                                    $originalDef->printStdout();
                                }
                            }

                            echo "varid = '$varid'\n";
                            echo "resultid = '$resultid'\n";

                            $opInformation["original_def"] = $originalDef;

                            $this->context->getCurrentFunc()->storeOpInformation($resultid, $opInformation);
                        } else {
                            echo "ARRAYDIM_FETCH '$arrayDim' 3\n";
                            // we are in the middle of the chain thus we can access the previous chained object
                            $previousOpInformation = $this->context->getCurrentFunc()->getOpInformation($varid);

                            echo "varid = '$varid'\n";
                            echo "resultid = '$resultid'\n";

                            foreach ($previousOpInformation["chained_results"] as $previousChainedResult) {
                                echo "ARRAYDIM_FETCH '$arrayDim' 3b\n";
                                $newArr = $previousChainedResult->getOrCreateDefArrayIndex($arrayDim)[1];
                                $previousChainedResult->printStdout();
                                $newArr->printStdout();

                                // just for the flow
                                var_dump($previousOpInformation["array_dim"]);
                                $newArr->original->setDef($previousOpInformation["original_def"]);
                                $newArr->original->setArrayIndexAccessor($previousOpInformation["array_dim"]);

                                $opInformation["chained_results"][] = $newArr;
                            }

                            $opInformation["original_def"] = $previousOpInformation["original_def"];
                            $opInformation["def_assign"] = $previousOpInformation["def_assign"];

                            $this->context->getCurrentFunc()->storeOpInformation($resultid, $opInformation);
                        }

                        break;
                    


                    case Opcodes::PROPERTY_FETCH:
                        $propertyName = $instruction->getProperty(MyInstruction::PROPERTY_NAME);
                        $originalDef = $instruction->getProperty(MyInstruction::ORIGINAL_DEF);

                        $varid = $instruction->getProperty(MyInstruction::VARID);
                        $resultid = $instruction->getProperty(MyInstruction::RESULTID);

                        $opInformation = [];
                        $opInformation["chained_results"] = [];
                        $opInformation["def_assign"] = HelpersAnalysis::getAssignedDefOfPreviousInstruction($code, $index);
                        $opInformation["array_dim"] = null;

                        echo "PROPERTY_FETCH '$propertyName' 1\n";
                        echo "varid '$varid' 1\n";
                        echo "resultid '$resultid' 1\n";

                        // beginning of the chain: $originalDef->foo->bar
                        if (!is_null($originalDef)) {
                            $originalDef->setId(0);
                            echo "PROPERTY_FETCH '$propertyName' 2\n";
                            $originalDef->printStdout();
                            //if ($originalDef->isType(MyDefinition::TYPE_PROPERTY)) {
                            echo "PROPERTY_FETCH '$propertyName' 2 bb getid = '".$this->currentMyBlock->getId()."'\n";
                            $defsFound = ResolveDefs::selectProperties(
                                $this->context,
                                $this->defs->getOutMinusKill($this->currentMyBlock->getId()),
                                $originalDef,
                                $propertyName
                            );
                            /*
                            } else {
                            echo "PROPERTY_FETCH '$propertyName' 2 cc\n";
                            $defsFound = ResolveDefs::selectStaticProperty(
                                $this->context,
                                $this->defs,
                                $originalDef,
                                false,
                                false
                            );
                            }*/

                            foreach ($defsFound as $defFound) {
                                echo "PROPERTY_FETCH '$propertyName' 2 dd\n";

                                // just for the flow
                                $defFound->original->setDef($originalDef);
                                $defFound->original->setPropertyAccessor($propertyName);

                                $defFound->printStdout();
                            }

                            $opInformation["original_def"] = $originalDef;
                            $opInformation["chained_results"] = $defsFound;
                        } else {
                            echo "PROPERTY_FETCH '$propertyName' 3\n";
                            // we are in the middle of the chain thus we can access the previous chained object
                            $previousOpInformation = $this->context->getCurrentFunc()->getOpInformation($varid);
                            $opInformation["def_assign"] = $previousOpInformation["def_assign"];
                            $opInformation["original_def"] = $previousOpInformation["original_def"];

                            foreach ($previousOpInformation["chained_results"] as $previousChainedResult) {
                                echo "PROPERTY_FETCH '$propertyName' 3 bis 1\n";
                                $previousChainedResult->printStdout();

                                $idObject = $previousChainedResult->getObjectId();
                                $tmpMyClass = $this->context->getObjects()->getMyClassFromObject($idObject);

                                if (!is_null($tmpMyClass)) {
                                    echo "PROPERTY_FETCH '$propertyName' 3 bis 2\n";
                                    $property = $tmpMyClass->getProperty($propertyName);

                                    if (!is_null($property)
                                                && ResolveDefs::getVisibility($previousChainedResult, $property, $this->context->getCurrentFunc())) {
                                        echo "PROPERTY_FETCH '$propertyName' 3 bis 3\n";
                                        $property->printStdout();
                                        $opInformation["chained_results"][] = $property;
                                    }
                                }
                            }
                        }
                                
                        /*
                        if (HelpersAnalysis::isInstructionOfType($code, $index + 1, Opcodes::TEMPORARY)) {
                            echo "PROPERTY_FETCH '$propertyName' 4\n";
                            // we are at the end of chain
                            // bar ($temporary) = instance->foo->bar

                            $nextInstruction = HelpersAnalysis::getInstruction($code, $index + 1);
                            $temporary = $nextInstruction->getProperty(MyInstruction::TEMPORARY);
                            foreach ($opInformation["chained_results"] as $chainedResult) {
                                echo "PROPERTY_FETCH '$propertyName' 4 bb\n";
                                HelpersAnalysis::copyDefAttributes($chainedResult, $temporary, null, null);
                            }
                            $temporary->printStdout();
                        } elseif (HelpersAnalysis::isInstructionOfType($code, $index + 1, Opcodes::END_ASSIGN)) {
                            echo "PROPERTY_FETCH '$propertyName' 5\n";
                            // instance->foo->bar = ($defassign) $_GET["p"]

                            $nextInstruction = HelpersAnalysis::getInstruction($code, $index + 1);
                            $exprAssign = $nextInstruction->getProperty(MyInstruction::EXPR);

                            if (!is_null($exprAssign)
                                && $exprAssign->isAssign()) {
                                $defAssign = $exprAssign->getAssignDef();
                                echo "PROPERTY_FETCH '$propertyName' 6\n";
                                foreach ($opInformation["chained_results"] as $chainedResult) {
                                    echo "PROPERTY_FETCH '$propertyName' 7\n";


                                    $exprAssign->addDef($chainedResult);
                                    HelpersAnalysis::copyDefAttributes($defAssign, $chainedResult, null, $exprAssign);
                                    $chainedResult->printStdout();
                                }
                                $defAssign->printStdout();
                            }
                        } else {*/
                            echo "PROPERTY_FETCH '$propertyName' 8\n";
                            // we make the defs available for the next chained property
                            $this->context->getCurrentFunc()->storeOpInformation($resultid, $opInformation);
                        //}

                        break;
                    

                    case Opcodes::ARGUMENT:
                        $varid = $instruction->getProperty(MyInstruction::VARID);
                        $idparam = $instruction->getProperty("idparam");
                        $def = $instruction->getProperty("argdef$idparam");
                        $expr = $instruction->getProperty("argexpr$idparam");
    
                        $opDataVar = $this->context->getCurrentFunc()->getOpInformation($varid);
    
                        echo "ARGUMENT1 = '$idparam'\n";
                        echo "varid = '$varid'\n";
                        if (isset($opDataVar["chained_results"])) {
                            $mergedState = HelpersAnalysis::mergeDefsBlockIdStates(
                                $opDataVar["chained_results"],
                                $this->context->getCurrentBlock()->getId()
                            );

                            $def->setState($mergedState, $this->context->getCurrentBlock()->getId());
                            

                            echo "ARGUMENT2\n";
                            $def->printStdout();
                        }

                        break;
        

                    case Opcodes::VARIABLE_FETCH:
                        $varid = $instruction->getProperty(MyInstruction::VARID);
                        $exprid = $instruction->getProperty(MyInstruction::EXPRID);
                        $variable = $instruction->getProperty(MyInstruction::DEF);
                        $expr = $instruction->getProperty(MyInstruction::EXPR);

                        $id = is_null($varid) ? $exprid : $varid;

                        $opInformation = [];
                        echo "VARIABLE_FETCH\n";
                        if (!is_null($expr)) {
                            echo "VARIABLE\n";

                            echo "varid = '$varid'\n";
                            echo "exprid = '$exprid'\n";
                            echo "id = '$id'\n";
                            echo "currentMyBlock id = '".$this->currentMyBlock->getId()."'\n";

                            $variable->printStdout();

                            $defsFound = ResolveDefs::selectDefinitions(
                                $this->context,
                                $this->defs->getOutMinusKill($this->currentMyBlock->getId()),
                                $variable,
                                true
                            );

                            $newDefFounds = [];
                            foreach ($defsFound as $defFound) {
                                if (!is_null($defFound->getParamToArg())) {
                                    $param = $defFound;
                                    $defFound = $defFound->getParamToArg();
                                    
                                    // the current/default state of the argument becomes the currentstate of the param
                                    // it allows to propagate the state within the function
                                    /*
                                    $currentState = $defFound->getCurrentState();
                                    $defFound->unsetState($defFound->getBlockId());
                                    $defFound->setBlockId($param->getBlockId());
                                    $defFound->setState($currentState, $param->getBlockId());
                                    */
                                }

                                $newDefFounds[] = $defFound;
                                echo "VARIABLE_FETCH 2\n";
                                $defFound->printStdout();
                            }

                            $opInformation = [];
                            $opInformation["chained_results"] = $newDefFounds;
                            $opInformation["original_def"] = $variable;
                            $opInformation["array_dim"] = null;

                            $this->context->getCurrentFunc()->storeOpInformation($id, $opInformation);
                        }
    
                        break;
                    

                    case Opcodes::END_ASSIGN:
                        $varid = $instruction->getProperty(MyInstruction::VARID);
                        $exprid = $instruction->getProperty(MyInstruction::EXPRID);
                        $def = $instruction->getProperty(MyInstruction::DEF);
                        $literal = $instruction->getProperty(MyInstruction::LITERAL);

                        $opVarData = $this->context->getCurrentFunc()->getOpInformation($varid);
                        $opExprData = $this->context->getCurrentFunc()->getOpInformation($exprid);

                        echo "END_ASSIGN1\n";
                        echo "varid = '$varid'\n";
                        echo "exprid = '$exprid'\n";

                        if (is_null($opExprData) && !is_null($literal)) {
                            echo "END_ASSIGN1 opExprData null\n";
                            $opExprData["chained_results"] = [];
                            $opExprData["chained_results"][] = $literal;
                        }

                        // return function $def case for instance
                        if (is_null($opVarData) && !is_null($def)) {
                            echo "END_ASSIGN1 opVarData null\n";
                            $opVarData["chained_results"] = [];
                            $opVarData["chained_results"][] = $def;
                        }

                        // don't need to resolve variable we have already access to it
                        // ssa = 1) result=var3 2) expr=var3
                        if (!is_null($opExprData)) {
                            /*
                            $mergedState = HelpersAnalysis::mergeDefsBlockIdStates(
                                $opExprData["chained_results"],
                                $this->context->getCurrentBlock()->getId()
                            );
                            */

                            $mergedStates = HelpersAnalysis::mergeAllStates(
                                $opExprData["chained_results"]
                            );

                            echo "END_ASSIGN2\n";
                            if (!is_null($opVarData)) {
                                echo "END_ASSIGN3\n";
                                foreach ($opVarData["chained_results"] as $chainedResult) {
                                    echo "END_ASSIGN5 a '".$this->context->getCurrentBlock()->getId()."'\n";
                                    $chainedResult->printStdout();

                                    HelpersAnalysis::copyStates($mergedStates, $chainedResult);

                                    //$chainedResult->setState($mergedState, $this->context->getCurrentBlock()->getId());
                                    
                                    echo "END_ASSIGN5 b\n";
                                    $chainedResult->printStdout();
                                }
                            }
                        }
                        /*
                        else {

                            echo "END_ASSIGN EXPR NULL\n";
                            $variable->printStdout();
                            $defsFound = ResolveDefs::selectDefinitions(
                                $this->context,
                                $this->defs->getOutMinusKill($this->currentMyBlock->getId()),
                                $variable,
                                true
                            );

                            foreach($defsFound as $defFound) {
                                echo "defFound\n";
                                $defFound->printStdout();
                            }
                        }*/

                        /*
                        $listOfMyTemp = [];
                        if ($instruction->isPropertyExist(MyInstruction::PHI)) {
                            for ($i = 0; $i < $instruction->getProperty(MyInstruction::PHI); $i++) {
                                $listOfMyTemp[] = $instruction->getProperty("temp_".$i);
                            }
                        } else {
                            $listOfMyTemp[] = $instruction->getProperty(MyInstruction::TEMPORARY);
                        }
                        echo "TEMPORARY 1\n";

                        foreach ($listOfMyTemp as $tempDefa) {
                            echo "TEMPORARY 2\n";
                            $tempDefa->printStdout();

                            $tempDefaMyExpr = $tempDefa->getExpr();
                            $defAssignMyExpr = $tempDefaMyExpr->getAssignDef();

                            HelpersAnalysis::copyDefAttributes($tempDefa, $defAssignMyExpr, null);
                            $defAssignMyExpr->printStdout();

                            $sourceArr = $this->context->inputs->getSourceArrayByName(
                                $tempDefa,
                                $tempDefa->getArrayValue()
                            );

                            // if we use directly echo $_GET["b"];
                            if (!is_null($sourceArr)) {
                                $tempDefa->setArrayValue("PROGPILOT_ALL_INDEX_TAINTED");
                                $tempDefa->setLabel($sourceArr->getLabel());
                            }

                            if ($tempDefaMyExpr->isAssign() && !$tempDefaMyExpr->isAssignIterator()) {
                                ArrayAnalysis::copyArray(
                                    $this->context,
                                    $this->defs->getOutMinusKill($tempDefa->getBlockId()),
                                    $tempDefa,
                                    $tempDefa->getArrayValue(),
                                    $defAssignMyExpr,
                                    $defAssignMyExpr->getArrayValue()
                                );
                            }

                            // stackclass is null
                            // so if we have document a object HTMLDocument is created
                            $myClassNew = \progpilot\Analysis\CustomAnalysis::defineObject(
                                $this->context,
                                $tempDefa,
                                null
                            );

                            if (!is_null($myClassNew)) {
                                $objectId = $this->context->getObjects()->addObject();

                                $tempDefa->addType(MyDefinition::TYPE_INSTANCE);
                                $tempDefa->setObjectId($objectId);

                                $myClass = $this->context->getClasses()->getMyClass($myClassNew->getName());

                                if (is_null($myClass)) {
                                    $myClass = new MyClass(
                                        $tempDefa->getLine(),
                                        $tempDefa->getColumn(),
                                        $myClassNew->getName()
                                    );
                                }

                                $this->context->getObjects()->addMyclassToObject($objectId, $myClass);
                            }
                            /////////////////////////////////////////////////////////////

                            $tainted = $tempDefa->isTainted();
                            $stackClass = null;

                            if ($tempDefa->isType(MyDefinition::TYPE_PROPERTY)) {

                                $stackClass = ResolveDefs::propertyClass($this->context, $this->defs, $tempDefa);
                                $classOfTempDefArr = $stackClass[count($stackClass) - 1];

                                foreach ($classOfTempDefArr as $classOfTempDef) {
                                    $objectIdTmp = $classOfTempDef->getObjectId();
                                    $myClassFromObject =
                                        $this->context->getObjects()->getMyClassFromObject($objectIdTmp);

                                    if (!is_null($myClassFromObject)) {
                                        $sourceTmp = $this->context->inputs->getSourceByName(
                                            $this->context,
                                            $stackClass,
                                            $tempDefa,
                                            false,
                                            $myClassFromObject->getName(),
                                            $tempDefa->getArrayValue()
                                        );

                                        if (!is_null($sourceTmp)) {
                                            $tainted = true;
                                            $tempDefa->setLabel($sourceTmp->getLabel());
                                        }
                                    }
                                }

                            } else {
                                $sourceTmp = $this->context->inputs->getSourceByName(
                                    $this->context,
                                    null,
                                    $tempDefa,
                                    false,
                                    false,
                                    $tempDefa->getArrayValue()
                                );

                                if (!is_null($sourceTmp)) {
                                    $tainted = true;
                                    $tempDefa->setLabel($sourceTmp->getLabel());
                                }
                            }

                            $tempDefa->setTainted($tainted);



                            $nextInstruction = $code[$index - 1];

                            if ($nextInstruction->getOpcode() === Opcodes::PROPERTY_FETCH) {
                                $defs = array($tempDefa);
                            } else {
                                $defs = ResolveDefs::temporarySimple(
                                    $this->context,
                                    $this->defs,
                                    $tempDefa,
                                    $tempDefaMyExpr->isAssignIterator(),
                                    $tempDefaMyExpr->isAssign()
                                );
                            }

                            ValueAnalysis::updateStorageToExpr($tempDefaMyExpr);
                            $storageCast = ValueAnalysis::$exprsCast[$tempDefaMyExpr];
                            $storageKnownValues = ValueAnalysis::$exprsKnownValues[$tempDefaMyExpr];

                            $def = $tempDefa;

                            foreach ($defs as $def) {
                                $safe = AssertionAnalysis::temporarySimple(
                                    $this->context,
                                    $this->defs,
                                    $this->currentMyBlock,
                                    $def,
                                    $tempDefa
                                );

                                echo "TEMPORARY 3\n";
                                $def->printStdout();

                                    $visibility = ResolveDefs::getVisibilityFromInstances(
                                        $this->context,
                                        $this->defs->getOutMinusKill($def->getBlockId()),
                                        $defAssignMyExpr
                                    );

                                $tempDefaMyExpr->addDef($def);

                                                                if ($visibility) {
                                                                    $storageCast[] = $tempDefa->getCast();
                                                                    $storageKnownValues["".$tempDefa->getId().""][] = $def->getLastKnownValues();
                                                                    $def->setIsEmbeddedByChars($tempDefa->getIsEmbeddedByChars(), true);
                                                                }

                                                                if ($visibility && !$safe) {
                                TaintAnalysis::setTainted($def->isTainted(), $defAssignMyExpr, $tempDefaMyExpr);
                                ValueAnalysis::copyValues($def, $defAssignMyExpr);

                                if ($def->isType(MyDefinition::TYPE_COPY_ARRAY)) {
                                    $defAssignMyExpr->setCopyArrays($def->getCopyArrays());
                                    $defAssignMyExpr->addType(MyDefinition::TYPE_COPY_ARRAY);
                                }


                                if ($def->isType(MyDefinition::TYPE_INSTANCE)) {
                                    $defAssignMyExpr->addType(MyDefinition::TYPE_INSTANCE);
                                    $defAssignMyExpr->setObjectId($def->getObjectId());
                                }

                                if ($def->getLabel() === MyDefinition::SECURITY_HIGH) {
                                    \progpilot\Analysis\CustomAnalysis::disclosureOfInformation(
                                        $this->context,
                                        $this->defs,
                                        $defAssignMyExpr
                                    );
                                }
                                $defAssignMyExpr->printStdout();
                                //}

                                                                if ($def->isType(MyDefinition::TYPE_ARRAY)) {
                                                                    $defAssignMyExpr->setArrayValue($def->getArrayValue());
                                                                    $defAssignMyExpr->addType(MyDefinition::TYPE_ARRAY);
                                                                }

                                // vérifier s'il y a pas de concat
                                // mise a jour de l'object

                                if ($def->isType(MyDefinition::TYPE_INSTANCE)) {
                                    $defAssignMyExpr->addType(MyDefinition::TYPE_INSTANCE);
                                    $defAssignMyExpr->setObjectId($def->getObjectId());

                                    $tmpMyClass = $this->context->getObjects()->getMyClassFromObject(
                                        $def->getObjectId()
                                    );
                                    if (!is_null($tmpMyClass)) {
                                        foreach ($tmpMyClass->getProperties() as $property) {
                                            $myDefTemp = new MyDefinition(
                                                $tempDefa->getLine(),
                                                $tempDefa->getColumn(),
                                                $tempDefa->getName()
                                            );
                                            $myDefTemp->addType(MyDefinition::TYPE_PROPERTY);
                                            $myDefTemp->property->setProperties($property->property->getProperties());
                                            $myDefTemp->setBlockId($tempDefa->getBlockId());
                                            $myDefTemp->setSourceMyFile($tempDefa->getSourceMyFile());
                                            $myDefTemp->setId($tempDefa->getId());

                                            $defsFound = ResolveDefs::selectProperties(
                                                $this->context,
                                                $this->defs->getOutMinusKill($tempDefa->getBlockId()),
                                                $myDefTemp,
                                                true
                                            );

                                            foreach ($defsFound as $defFound) {
                                                if ($defFound->isType(MyDefinition::TYPE_COPY_ARRAY)) {
                                                    $property->setCopyArrays($defFound->getCopyArrays());
                                                    $property->addType(MyDefinition::TYPE_COPY_ARRAY);
                                                }

                                                TaintAnalysis::setTainted(
                                                    $defFound->isTainted(),
                                                    $property,
                                                    $defFound->getTaintedByExpr()
                                                );

                                                if ($defFound->isSanitized()) {
                                                    $property->setSanitized(true);
                                                    foreach ($defFound->getTypeSanitized() as $typeSanitized) {
                                                        $property->addTypeSanitized($typeSanitized);
                                                    }
                                                }
                                            }
                                        }
                                    }
                                }
                            }

                            ValueAnalysis::$exprsCast[$tempDefaMyExpr] = $storageCast;
                            ValueAnalysis::$exprsKnownValues[$tempDefaMyExpr] = $storageKnownValues;

                        }
*/
                        break;
                    

                    case Opcodes::FUNC_CALL:
                        $funcName = $instruction->getProperty(MyInstruction::FUNCNAME);
                        $arrFuncCall = $instruction->getProperty(MyInstruction::ARR);
                        $myFuncCall = $instruction->getProperty(MyInstruction::MYFUNC_CALL);
                        $myExpr = $instruction->getProperty(MyInstruction::EXPR);

                        echo "FUNC_CALL name '$funcName'\n";
                        
                        if ($funcName === "call_user_func" || $funcName === "call_user_func_array") {
                            if ($instruction->isPropertyExist("argdef0")) {
                                $defArg = $instruction->getProperty("argdef0");
                                
                                $newInst = new MyInstruction(Opcodes::FUNC_CALL);
                        
                                $myFunctionCall = new MyFunction("tmp");
                                $myFunctionCall->setLine($myFuncCall->getLine());
                                $myFunctionCall->setColumn($myFuncCall->getColumn());
                                $myFunctionCall->setSourceMyFile($myFuncCall->getSourceMyFile());
                                    
                                if ($funcName === "call_user_func") {
                                    for ($nbParams = 1; $nbParams < $myFuncCall->getNbParams(); $nbParams ++) {
                                        $oldDefArg = $instruction->getProperty("argdef$nbParams");
                                        $oldExprArg = $instruction->getProperty("argexpr$nbParams");
                                        
                                        $newNbParams = $nbParams - 1;
                                        $newInst->addProperty("argdef$newNbParams", $oldDefArg);
                                        $newInst->addProperty("argexpr$newNbParams", $oldExprArg);
                                    }
                                    
                                    $myFunctionCall->setNbParams($myFuncCall->getNbParams() - 1);
                                } else {
                                    if ($instruction->isPropertyExist("argdef1")) {
                                        $defArgParam = $instruction->getProperty("argdef1");
                                        
                                        if ($defArgParam->isType(MyDefinition::TYPE_COPY_ARRAY)) {
                                            $newNbParams = 0;
                                            foreach ($defArgParam->getCopyArrays() as $copyArray) {
                                                $copyArray[1]->removeType(MyDefinition::TYPE_ARRAY);
                                                $newInst->addProperty("argdef$newNbParams", $copyArray[1]);
                                                $newInst->addProperty("argexpr$newNbParams", $copyArray[1]->getExpr());
                                                
                                                $newNbParams ++;
                                            }
                                            
                                            $myFunctionCall->setNbParams($newNbParams);
                                        }
                                    }
                                }
                                
                                $newInst->addProperty(MyInstruction::MYFUNC_CALL, $myFunctionCall);
                                $newInst->addProperty(MyInstruction::EXPR, $myExpr);
                                $newInst->addProperty(MyInstruction::ARR, $arrFuncCall);
                                 
                                foreach ($defArg->getLastKnownValues() as $lastValue) {
                                    $myFunctionCall->setName($lastValue);
                                    $newInst->addProperty(MyInstruction::FUNCNAME, $lastValue);

                                    $this->funcCall(
                                        $myCode,
                                        $newInst,
                                        $code,
                                        $index,
                                        $lastValue,
                                        $arrFuncCall,
                                        $myFunctionCall
                                    );
                                }
                            }
                        } else {
                            echo "FUNC_CALL name '$funcName' 2\n";
                            $this->funcCall(
                                $myCode,
                                $instruction,
                                $code,
                                $index,
                                $funcName,
                                $arrFuncCall,
                                $myFuncCall
                            );
                        }

                        break;
                }

                $index = $index + 1;
            }
        } while (isset($code[$index]) && $index <= $myCode->getEnd());
    }
}
