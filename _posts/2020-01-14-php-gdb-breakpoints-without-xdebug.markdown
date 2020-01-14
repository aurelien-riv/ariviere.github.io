---
layout: default
title: "Debugging PHP: Set breakpoints on PHP code using a C debugger"
date: 2020-01-14 20:30:00 +0200
categories: [php]
tags: [debugging, php-src, GDB]
description: Sometimes you need to debug a PHP process without XDebug and you use var_dump and die. I show you a wait to emulate XDebug using GDB.
excerpt: Sometimes you need to debug a PHP process without XDebug and you use var_dump and die. I show you a wait to emulate XDebug using GDB.
---

# How to set a breakpoint on PHP without XDebug?

Sometimes, you'd like to put a breakpoint on a PHP program, but you can't, maybe simply because you don't have a PHP debugger installed, or maybe because the function you want to break on is defined inside PHP itself. I'll show you a way to define your breakpoints using GDB, a C debugger. And if that idea sounds weird to you, maybe you should read that blog post: [should you use a C debugger to debug PHP?][2020-01-14-php-why-should-you-use-c-debugger].

## GDB instructions

Earlier, I wrote a post to explain [how to get the current php backtrace using GDB][2019-12-07-which-function-php-executing]. Please read it before you carry on as it gave you a macro definition for "phpbt" (used in the examples) and explained some members of the zend\_execute\_data structure.

**⚠️  These instructions won't work before PHP7, and may stop working on future versions.**

### Break on a function
```
break execute_ex if \
	executor_globals.current_execute_data != 0 && \
        (zend_execute_data *)executor_globals.current_execute_data->func.common.scope == 0 && \
        ((zend_execute_data *)executor_globals.current_execute_data)->func.common.function_name != 0 && \
        $_streq((char *)(((zend_execute_data *)executor_globals.current_execute_data)->func.common.function_name.val), "function_name")
```

### Break on an internal function
```
break execute_internal if \
	executor_globals.current_execute_data != 0 && \
        (zend_execute_data *)executor_globals.current_execute_data->func.common.scope == 0 && \
        ((zend_execute_data *)executor_globals.current_execute_data)->func.common.function_name != 0 && \
        $_streq((char *)(((zend_execute_data *)executor_globals.current_execute_data)->func.common.function_name.val), "internal_function_name")
```

### Break on a method
```
break execute_ex if \
        executor_globals.current_execute_data != 0 && \
        (zend_execute_data *)executor_globals.current_execute_data->func.common.scope != 0 && \
        ((zend_execute_data *)executor_globals.current_execute_data)->func.common.function_name != 0 && \
        $_streq((char *)(((zend_execute_data *)executor_globals.current_execute_data)->func.common.function_name.val), "method_name") && \
        $_streq((char *)(((zend_execute_data *)executor_globals.current_execute_data)->func.common.scope.name.val), "Class\\Name")
```

### Break on an internal method
```
break execute_internal if \
        executor_globals.current_execute_data != 0 && \
        (zend_execute_data *)executor_globals.current_execute_data->func.common.scope != 0 && \
        ((zend_execute_data *)executor_globals.current_execute_data)->func.common.function_name != 0 && \
        $_streq((char *)(((zend_execute_data *)executor_globals.current_execute_data)->func.common.function_name.val), "method_name") && \
        $_streq((char *)(((zend_execute_data *)executor_globals.current_execute_data)->func.common.scope.name.val), "Class\\Name")
```

## Examples
### Break on a method
```
ari@ari-ThinkPad-T580:~/dev/bs-mg2$ gdb php
[...]
(gdb) break execute_ex if \
        executor_globals.current_execute_data != 0 && \
        (zend_execute_data *)executor_globals.current_execute_data->func.common.scope != 0 && \
        ((zend_execute_data *)executor_globals.current_execute_data)->func.common.function_name != 0 && \
        $_streq((char *)(((zend_execute_data *)executor_globals.current_execute_data)->func.common.function_name.val), "loadClass") && \
        $_streq((char *)(((zend_execute_data *)executor_globals.current_execute_data)->func.common.scope.name.val), "Composer\\Autoload\\ClassLoader")
Breakpoint 1 at 0x34cc00: file ./Zend/zend_vm_execute.h, line 54806.

(gdb) run bin/magento 
Starting program: /usr/bin/php bin/magento
[Thread debugging using libthread_db enabled]
Using host libthread_db library "/lib/x86_64-linux-gnu/libthread_db.so.1".

Breakpoint 1, execute_ex (ex=0x7ffff321cf90) at ./Zend/zend_vm_execute.h:54806
54806	./Zend/zend_vm_execute.h: Aucun fichier ou dossier de ce type.

(gdb) phpbt
$1 = {0x7ffff325e638 "Composer\\Autoload\\ClassLoader", 0x7ffff329da90 "loadClass"}
$2 = {0x555555c6a508 "spl_autoload_call"}
$3 = {0x555555c482a8 "class_exists"}
$4 = {0x18 <error: Cannot access memory at address 0x18>}
$5 = {0x7ffff328b608 "composerRequire56b7158ffa9e44c1ed86ea1ec4e334a7"}
$6 = {0x7ffff328b2e8 "ComposerAutoloaderInit56b7158ffa9e44c1ed86ea1ec4e334a7", 0x7ffff3202a40 "getLoader"}
$7 = {0x18 <error: Cannot access memory at address 0x18>}
$8 = {0x18 <error: Cannot access memory at address 0x18>}
$9 = {0x18 <error: Cannot access memory at address 0x18>}
$10 = {0x18 <error: Cannot access memory at address 0x18>}
```

### Break on an internal method
```
ari@ari-ThinkPad-T580:~/dev/bs-mg2$ gdb php
[...]
(gdb) break execute_internal if \
        executor_globals.current_execute_data != 0 && \
        (zend_execute_data *)executor_globals.current_execute_data->func.common.scope != 0 && \
        ((zend_execute_data *)executor_globals.current_execute_data)->func.common.function_name != 0 && \
        $_streq((char *)(((zend_execute_data *)executor_globals.current_execute_data)->func.common.function_name.val), "bind") && \
        $_streq((char *)(((zend_execute_data *)executor_globals.current_execute_data)->func.common.scope.name.val), "Closure")
Breakpoint 1 at 0x34b530: file ./Zend/zend_execute.c, line 2083.

(gdb) run bin/magento 
Starting program: /usr/bin/php bin/magento
[Thread debugging using libthread_db enabled]
Using host libthread_db library "/lib/x86_64-linux-gnu/libthread_db.so.1".

Breakpoint 1, execute_internal (execute_data=0x7ffff321cdf0, return_value=0x7ffff321cde0) at ./Zend/zend_execute.c:2083
2083	./Zend/zend_execute.c: Aucun fichier ou dossier de ce type.

(gdb) phpbt
$1 = {0x555555d0ba58 "Closure", 0x555555d0bcb8 "bind"}
$2 = {0x7ffff326f138 "Composer\\Autoload\\ComposerStaticInit56b7158ffa9e44c1ed86ea1ec4e334a7", 0x7ffff3321090 "getInitializer"}
$3 = {0x7ffff328b2e8 "ComposerAutoloaderInit56b7158ffa9e44c1ed86ea1ec4e334a7", 0x7ffff3202a40 "getLoader"}
$4 = {0x18 <error: Cannot access memory at address 0x18>}
$5 = {0x18 <error: Cannot access memory at address 0x18>}
$6 = {0x18 <error: Cannot access memory at address 0x18>}
$7 = {0x18 <error: Cannot access memory at address 0x18>}
```

[2020-01-14-php-why-should-you-use-c-debugger]: {% post_url 2020-01-14-php-why-should-you-use-c-debugger %}
[2019-12-07-which-function-php-executing]: {% post_url 2019-12-07-which-function-php-executing %}       
