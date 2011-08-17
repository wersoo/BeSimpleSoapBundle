<?php

namespace BeSimple\SoapBundle\ServiceDefinition\Strategy;

use Zend\Soap\Wsdl;
use Zend\Soap\Wsdl\Strategy\AbstractStrategy;

class ArrayOfType extends AbstractStrategy
{
    const OCCURS_MIN = 'min';
    const OCCURS_MAX = 'max';

    public function support($type)
    {
        if (preg_match('#\[ *[0-9]*(?: *, *[0-9]*)?\]$#', $type)) {
            return true;
        }

        return false;
    }

    /**
     * Add an unbounded ArrayOfType based on the xsd:sequence syntax if type[] is detected in return value doc comment.
     *
     * @param string $type
     * @return string tns:xsd-type
     */
    public function addComplexType($type)
    {
        $nested      = $this->getNested($type);
        $nestedCount = count($nested);

        if($nestedCount) {
            $singularType = $this->getSingularType($type);

            foreach ($nested as $level => $occurs) {
                // if last array
                if ($nestedCount === $level + 1) {
                    // This is not an Array anymore, return the xsd simple type
                    $childType   = $this->getContext()->getType($singularType);
                    $complexType = $this->getTypeByOccurs($singularType, $occurs);
                } else {
                    $childType   = $this->getTypeAfterNestingLevel($singularType, $level + 1, $nested, $nestedCount);
                    $complexType = $this->getTypeAfterNestingLevel($singularType, $level, $nested, $nestedCount);
                }

                $complexTypePhp = $singularType;
                for ($i = 0; $i <= $level; $i++) {
                    $complexTypePhp .= $nested[$level][0];
                }

                $this->addSequenceType($complexType, $childType, $complexTypePhp, $occurs);
            }

            return $this->getTypeAfterNestingLevel($singularType, 0, $nested, $nestedCount);
        }
    }

    /**
     * From a nested defintion with type[], get the singular xsd:type
     *
     * @param  string $type
     * @return string
     */
    protected function getSingularType($type)
    {
        preg_match('#^([^\[]+)#', $type, $match);

        return $match[1];
    }

    /**
     * Return the array nesting level based on the type name
     *
     * @param  string $type
     * @return array
     */
    protected function getNested($type)
    {
        if (preg_match_all('#(?:\[ *(?P<'.self::OCCURS_MIN.'>[0-9]*)(?: *, *(?P<'.self::OCCURS_MAX.'>[0-9]*)?)?\])#', $type, $match, \PREG_SET_ORDER)) {
            foreach ($match as $i => $occurs) {
                if ('[]' === $occurs[0]) {
                    $occurs[self::OCCURS_MIN] = 0;
                    $occurs[self::OCCURS_MAX] = 'unbounded';
                } elseif (isset($occurs[self::OCCURS_MIN]) && !isset($occurs[self::OCCURS_MAX])) {
                    if (0 == $occurs[self::OCCURS_MIN]) {
                        throw new \InvalidArgumentException('You cannot have a table with 0 elements.');
                    }

                    $occurs[self::OCCURS_MAX] = $occurs[self::OCCURS_MIN];
                } elseif (isset($occurs[self::OCCURS_MIN], $occurs[self::OCCURS_MAX]) && empty($occurs[self::OCCURS_MAX])) {
                    $occurs[self::OCCURS_MAX] = 'unbounded';
                } elseif ($occurs[self::OCCURS_MIN] > $occurs[self::OCCURS_MAX]) {
                    throw new \InvalidArgumentException(sprintf('The max value cannot be smaller than min value (%s > %s).', $occurs[self::OCCURS_MIN], $occurs[self::OCCURS_MAX]));
                }

                $match[$i] = array(
                    $occurs[0],
                    self::OCCURS_MIN => $occurs[self::OCCURS_MIN],
                    self::OCCURS_MAX => $occurs[self::OCCURS_MAX],
                );
            }

            return $match;
        }

        return array();
    }

    protected function getTypeAfterNestingLevel($singularType, $level, array $nested = null, $nestedCount = null)
    {
        $arrayOf = '';

        for ($i = $level; $i < $nestedCount; $i++) {
            $arrayOf .= $this->getArrayOfByOccurs($nested[$i]);
        }

        return 'tns:'.$arrayOf.ucfirst(Wsdl::translateType($singularType));
    }

    /**
     * Return the ArrayOf or simple type name based on the singular xsdtype and the nesting level
     *
     * @param  string $singularType
     * @param  int    $level
     * @return string
     */
    protected function getTypeByOccurs($singularType, array $occurs)
    {
        return 'tns:'.$this->getArrayOfByOccurs($occurs).ucfirst(Wsdl::translateType($singularType));
    }

    protected function getArrayOfByOccurs(array $occurs)
    {
        if (0 == $occurs[self::OCCURS_MIN] && 'unbounded' === $occurs[self::OCCURS_MAX]) {
            $arrayOf = 'ArrayOf';
        } else {
            if ($occurs[self::OCCURS_MIN] !== $occurs[self::OCCURS_MAX]) {
                $occurs = $occurs[self::OCCURS_MIN].'.'.$occurs[self::OCCURS_MAX];
            } else {
                $occurs = $occurs[self::OCCURS_MIN];
            }

            $arrayOf = 'Array.'.$occurs.'.Of';
        }

        return $arrayOf;
    }

    /**
     * Append the complex type definition to the WSDL via the context access
     *
     * @param  string $arrayType      Array type name (e.g. 'ArrayOfArrayOfInt')
     * @param  string $childType      Qualified array items type (e.g. 'xsd:int', 'ArrayOfInt')
     * @param  string $phpArrayType   PHP type (e.g. 'int[][]', '\MyNamespace\MyClassName[][][]')
     * @return void
     */
    protected function addSequenceType($arrayType, $childType, $phpArrayType, array $occurs)
    {
        if (null !== $this->scanRegisteredTypes($phpArrayType)) {
            return;
        }

        // Register type here to avoid recursion
        $this->getContext()->addType($phpArrayType, $arrayType);

        $dom = $this->getContext()->toDomDocument();

        $complexType = $dom->createElement('xsd:complexType');
        $complexType->setAttribute('name', substr($arrayType, strpos($arrayType, ':') + 1));

        $sequence = $dom->createElement('xsd:sequence');

        $element = $dom->createElement('xsd:element');
        $element->setAttribute('name', 'item');
        $element->setAttribute('type', $childType);
        $element->setAttribute('minOccurs', $occurs[self::OCCURS_MIN]);
        $element->setAttribute('maxOccurs', $occurs[self::OCCURS_MAX]);
        $sequence->appendChild($element);

        $complexType->appendChild($sequence);

        $this->getContext()->getSchema()->appendChild($complexType);
    }
}