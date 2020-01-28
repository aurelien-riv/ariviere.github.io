---
layout: default
title: "Debugging PHP: Set a breakpoint on a string"
date: 2020-01-14 20:30:00 +0200
categories: [php]
tags: [debugging, php-src, GDB]
description: Sometimes you only have to find what's going wrong with only a string as clue. I give you a way to put a breakpoint on the creating of that string.
excerpt: Sometimes you only have to find what's going wrong with only a string as clue. I give you a way to put a breakpoint on the creating of that string.
---

# Can we set a breakpoint on a string in PHP?

Sometimes something goes wrong in a complex process, the error handling mecanism only logs a message without any context or backtrace, and the only solution you have is to perform a step by step debugging on that process until you meet that string... or maybe we can use GDB to break on the initialization of that string. 

**⚠️  These instructions won't work before PHP7, and may stop working on future versions.**

## GDB instructions

Earlier, I wrote a post to explain [how to get the current php backtrace using GDB][2019-12-07-which-function-php-executing]. Please read it before you carry on as it gave you a macro definition for "phpbt" (used in the examples) and explained some members of the zend\_execute\_data structure.

We are going to intercept the initiation of any string that contains what we are looking for, using the following expression:

```
break zend_string_init if persistent == 0 && $_regex(str, ".*any string.*")
```

Our expression is looking for a regex, which give you a lot of control, even if it adds a few overhead. Also, you can notice the presence of the "persistent" argument,  which says to the Zend Engine whether the string should be allocated on the current request only or be shared across the requests. Here, we are looking for runtime strings so we filter out persistent strings.

Here a simple example:

```
$ gdb php
GNU gdb (GDB) Fedora 8.3.50.20190824-26.fc31
Copyright (C) 2019 Free Software Foundation, Inc.
License GPLv3+: GNU GPL version 3 or later <http://gnu.org/licenses/gpl.html>
This is free software: you are free to change and redistribute it.
There is NO WARRANTY, to the extent permitted by law.
Type "show copying" and "show warranty" for details.
This GDB was configured as "x86_64-redhat-linux-gnu".
Type "show configuration" for configuration details.
For bug reporting instructions, please see:
<http://www.gnu.org/software/gdb/bugs/>.
Find the GDB manual and other documentation resources online at:
    <http://www.gnu.org/software/gdb/documentation/>.

For help, type "help".
Type "apropos word" to search for commands related to "word"...
Reading symbols from php...

(gdb) break zend_string_init if persistent == 0 && $_regex(str, "In ParameterBag.php.*")
Breakpoint 1 at 0x6030a9: zend_string_init. (123 locations)

(gdb) run bin/console 
Starting program: /usr/local/bin/php bin/console
[Thread debugging using libthread_db enabled]
Using host libthread_db library "/lib64/libthread_db.so.1".

Breakpoint 1, zend_string_init (str=0x7fffe9219ae1 "In ParameterBag.php line 102:</comment>", len=29, persistent=0) at /home/ariviere/dev/php-src/Zend/zend_string.h:155
155		zend_string *ret = zend_string_alloc(len, persistent);

(gdb) phpbt
$1 = {0x185e938 "substr"}
$2 = {0x7ffff7662c58 "Symfony\\Component\\Console\\Formatter\\OutputFormatter", 0x7ffff7730818 "format"}
$3 = {0x7ffff779c498 "Symfony\\Component\\Console\\Output\\Output", 0x7ffff77887d8 "write"}
$4 = {0x7ffff779c498 "Symfony\\Component\\Console\\Output\\Output", 0x7ffff7786358 "writeln"}
$5 = {0x7ffff776b798 "Symfony\\Component\\Console\\Application", 0x7ffff7667c98 "doRenderException"}
$6 = {0x7ffff776b798 "Symfony\\Component\\Console\\Application", 0x7ffff7667838 "renderException"}
$7 = {0x7ffff776b798 "Symfony\\Component\\Console\\Application", 0x7ffff778a4f8 "Symfony\\Component\\Console\\{closure}"}
$8 = {0x7ffff776b798 "Symfony\\Component\\Console\\Application", 0x7ffff76eecd8 "run"}
$9 = {0x18 <error: Cannot access memory at address 0x18>}
```

Great isn't it? well, not really...

## A matter of spherical COWs in the vacuum

Let consider this example:

{% highlight php %}
<?php

class Test 
{
    const HELLO_WORLD = "Hello World!\n";

    public function __toString()
    {
        return static::HELLO_WORLD;
    }
}

echo (new Test());
{% endhighlight %}

It shouldn't be hard to detect the moment we use that string, right? Let try to catch it:

```
 ariviere   master  ~   gdb php
GNU gdb (GDB) Fedora 8.3.50.20190824-26.fc31
Copyright (C) 2019 Free Software Foundation, Inc.
License GPLv3+: GNU GPL version 3 or later <http://gnu.org/licenses/gpl.html>
This is free software: you are free to change and redistribute it.
There is NO WARRANTY, to the extent permitted by law.
Type "show copying" and "show warranty" for details.
This GDB was configured as "x86_64-redhat-linux-gnu".
Type "show configuration" for configuration details.
For bug reporting instructions, please see:
<http://www.gnu.org/software/gdb/bugs/>.
Find the GDB manual and other documentation resources online at:
    <http://www.gnu.org/software/gdb/documentation/>.

For help, type "help".
Type "apropos word" to search for commands related to "word"...
Reading symbols from php...
(gdb) break zend_string_init if persistent == 0 && $_regex(str, "Hello.*")
Breakpoint 1 at 0x6030a9: zend_string_init. (123 locations)

(gdb) run test.php 
Starting program: /usr/local/bin/php test.php
Missing separate debuginfos, use: dnf debuginfo-install glibc-2.30-10.fc31.x86_64
[Thread debugging using libthread_db enabled]
Using host libthread_db library "/lib64/libthread_db.so.1".

Breakpoint 1, zend_string_init (str=0x7ffff768242e "Hello World!\\n\";\n\n    public function __toString()\n    {\n        return static::HELLO_WORLD;\n    }\n}\n\necho (new Test());\n", len=14, persistent=0)
    at /home/ariviere/dev/php-src/Zend/zend_string.h:155
155		zend_string *ret = zend_string_alloc(len, persistent);
Missing separate debuginfos, use: dnf debuginfo-install libxcrypt-4.4.10-2.fc31.x86_64 libxml2-2.9.10-2.fc31.x86_64 xz-libs-5.2.4-6.fc31.x86_64 zlib-1.2.11-20.fc31.x86_64

(gdb) phpbt
(gdb) c
Continuing.
Hello World!
[Inferior 1 (process 11257) exited normally]
```

First, we set our breakpoint to "Hello.*", so we're sure it matches our constant content.\\
Then, we run out program, as usual;\\
Until a string containing our source code is created. At that point, we have no PHP stack trace, as PHP is still parsing the code;\\
Finally, we asked the program to carry on, until we hit the breakpoint again, or until...\\
It ends.

### So, why it didn't worked?

PHP uses a copy-on-write mechanism, or COW. That means it won't allocate memory unless it (thinks it) needs it.

Take the previous example and simply add trim() around new Test(), and try to break on that string again. It works!\\
Now, remove the LF (\\n) character at the end of our constant and try again. It stopped working: as trim() no longer modify the string, PHP increased its reference count instead of duplicating it.

To break on it, you'll have to listen to string copies (even if that name is misleading as it's nothing but the incrementation of a int in a data structure), performed by zend_string_copy.

```
[...]
(gdb) break zend_string_copy if $_regex((char*)s.val, "Hello.*")
Breakpoint 1 at 0x6d5d75: zend_string_copy. (44 locations)

Breakpoint 1, zend_string_copy (s=0x7ffff767dbe0) at /home/ariviere/dev/php-src/Zend/zend_string.h:164
164		if (!ZSTR_IS_INTERNED(s)) {
(gdb) print (char*)s.val
$43 = 0x7ffff767dbf8 "Hello World!"
```

Great, we can now catch string copies, in addition to string initialization!

### So, does it work now?

Well... no. Our example, before the addition of trim(), still passes through our breakpoints.

[2020-01-14-php-why-should-you-use-c-debugger]: {% post_url 2020-01-14-php-why-should-you-use-c-debugger %}
[2019-12-07-which-function-php-executing]: {% post_url 2019-12-07-which-function-php-executing %}       
