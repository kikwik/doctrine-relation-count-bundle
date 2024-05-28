<?php

namespace Kikwik\DoctrineRelationCountBundle\Attribute;

#[\Attribute(\Attribute::TARGET_PROPERTY)]
class CountableRelation
{
    public string $targetProperty;

    public function __construct(
        string $targetProperty
    )
    {
        $this->targetProperty = $targetProperty;
    }
}