<?php
if (function_exists('opcache_reset')) {
    opcache_reset();
    echo "SUCCESS: Opcache has been cleared. The new code is now loaded into memory!";
} else {
    echo "Opcache is not enabled. No need to clear.";
}
