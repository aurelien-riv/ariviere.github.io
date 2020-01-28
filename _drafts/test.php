<?php

class Test 
{
    const HELLO_WORLD = "Hello World!";

    public function __toString()
    {
        return static::HELLO_WORLD;
    }
}

echo new Test();

