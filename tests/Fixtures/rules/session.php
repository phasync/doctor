<?php

function login($id)
{
    session_start();
    $_SESSION['user'] = $id;
}
function user()
{
    return $_SESSION['user'] ?? null;
}
function sid()
{
    return session_create_id();
}
