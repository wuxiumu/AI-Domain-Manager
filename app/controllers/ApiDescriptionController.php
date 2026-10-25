<?php

class ApiDescriptionController
{
    public function openapi(): void { json_out(ApiDescriptionService::openapi()); }
    public function discovery(): void { json_out(ApiDescriptionService::discovery()); }
    public function instructions(): void
    {
        header('Content-Type: text/plain; charset=utf-8');
        echo ApiDescriptionService::instructions();
    }
}
