<?php
echo password_hash('password', PASSWORD_BCRYPT, ['cost' => 12]);
