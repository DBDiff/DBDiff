<?php namespace DBDiff\SQLGen;


interface SQLGenInterface {
    public function getUp();
    public function getDown();
}
