# How to set a breakpoint on PHP without XDebug?

What do you do when you have to debug a PHP process without a PHP debugger? 
Die and var\_dump probably... or file\_put\_contents if you want to avoid crashing the site. 
Maybe there is another solution that doesn't implies altering the site, why not using a C debugger?

It may seem weird at the first glance, why would you install a C debugger on your server executing PHP, in order to install PHP, why not simply install (and configure) XDebug?

First, because I already explained how [GDB can help understanding what PHP's doing]. 

Then, because a PHP debugger adds an overhead to all the processes, contrary to a C debugger which will alter the process it is connected to.

## Limitations
Contrary to XDebug, these instructions don't permit (yet?) to add conditions based on variable values to your breakpoints. We could, but the conditions would be really complex as 
* we would have to fetch the variable or argument from internal structures, which is not trivial ;
* we would have to handle properly the value we get, and the process is not the same depending on the data type (object, array, string, int, ...) ;
* we would have to repeat the operation on array and object items, which increases the complexity again

## Advantages
XDebug is limited to user defined functions and methods. Using GDB, we can break on "internal" functions (such as file\_exists) and methods (such as PDOStatement::execute), which are defined inside PHP or its modules.

## GDB instructions
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
