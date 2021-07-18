---
layout: default
title: "Are PHP's short echo tag faster than echo?"
description: "Is &lt;?= faster than &lt;?php echo ?"
excerpt: "Is &lt;?= faster than &lt;?php echo ?"
date: 2021-07-18 14:30:00 +0200
categories: [PHP]
tags: [performance]
---

# Are PHP's short echo tag faster than echo?

I've heard some people saying `<?=` was faster than `<?php echo`, or also more secure. 
Actually, there is no difference between these two forms. 
I can't be sure it has never been true, but for recent versions of PHP, they are strictly equivalent.

I'll use PHP's [vld][vld] extension, which permit to print the OPCodes that are executed. 

## Using short echo tags

```php
<?= 1; ?>
```

```
$ php -dextension=vld.so -dvld.active=1 test.php
Finding entry points
Branch analysis from position: 0
1 jumps found. (Code = 62) Position 1 = -2
filename: /var/www/html/test.php
function name: (null)
number of ops: 5
compiled vars: none
line #* E I O op fetch ext return operands
-------------------------------------------------------------------------------------
    1 0 E > EXT_STMT                                                 
          1 ECHO 1
    2 2 EXT_STMT                                                 
          3 ECHO '%0A'
    3 4 > RETURN 1

branch: # 0; line: 1- 3; sop: 0; eop: 4; out0: -2
path #1: 0, 
1
```

## Using echo 

```php
<?php echo 1; ?>
```

```
$ php -dextension=vld.so -dvld.active=1 test.php
Finding entry points
Branch analysis from position: 0
1 jumps found. (Code = 62) Position 1 = -2
filename: /var/www/html/test.php
function name: (null)
number of ops: 5
compiled vars: none
line #* E I O op fetch ext return operands
-------------------------------------------------------------------------------------
    1 0 E > EXT_STMT                                                 
          1 ECHO 1
    2 2 EXT_STMT                                                 
          3 ECHO '%0A'
    3 4 > RETURN 1

branch: # 0; line: 1- 3; sop: 0; eop: 4; out0: -2
path #1: 0, 
1
```

## Conclusion

Both scripts output exactly the same opcodes. That means that although the lexer (the first part of the interpretation or compilation process) doesn't read the same code, it outputs the very same instructions.

Now some may say short echo tags may be faster to read for the lexer, which may not be false (depending on the execution flow of the lexer), but the gain would be negligible, and once the file has been parsed, OPCache will store the same `ECHO '%0A'` (assuming you use OPCache, which will improve your performance much more than trying to reduce the lexer's work) so there will be no difference.

Using echo or short echo tags is only a matter of choice and readability, use the one you prefer (except if your project's or company's coding standard tells you to use one or the other).


[vld]: https://pecl.php.net/package/vld
