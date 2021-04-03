<?php


namespace Lib;


interface ServerInterface
{

    static public function start($callback);

    static public function autoCreate();

    public function setServer($AppSvr);

    public function setProcessName($name);

    public function run($opt);

    public function connect();
}