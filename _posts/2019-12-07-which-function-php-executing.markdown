---
layout: default
title: "Debugging PHP: Knowing which function PHP is executing now"
date: 2019-12-07 15:00:00 +0200
categories: [php]
tags: [debugging, GDB, php-src]
---

# How to know which function PHP is currently executing and get its stack trace?

As far as I know, XDebug can't break on demand, without existing breakpoints or debug_break instruction.

So, let suppose your code seems to be stuck on an infinite loop, or doing something really slow. In my case, Magento was executing thousands of SQL queries according to its general log, but I had no idea neither why nor where it was doing so.

As you may know, PHP is written in C, so we will debug it as any C application. I used that method with the CLI and FPM SAPIs on GNU/Linux, so I won't cover mod_php SAPI and other operating systems.

**⚠️  PHP has to be compiled with debugging symbol so everything works!**

## Get the PID of your PHP process

Using _ps_, let list running PHP processes :

### PHP-FPM

```
ari@ari-ThinkPad-T580:~/Documents/perso/ariviere.github.io/_posts$ ps aux | grep php
root     17670  0.0  0.2 621184 36772 ?        Ss   déc.06   0:01 php-fpm: master process (/etc/php/7.2/fpm/php-fpm.conf)
ari      17700  0.0  0.1 623484 16952 ?        S    déc.06   0:00 php-fpm: pool www
ari      17706  0.0  1.0 705948 170288 ?       R    déc.06   0:03 php-fpm: pool www
ari      17708  0.0  0.1 623484 16952 ?        S    déc.06   0:00 php-fpm: pool www
ari      29609  0.0  0.0  21536   948 pts/8    S+   13:28   0:00 grep --color=auto php
```

As you can see, there are several PHP-FPM processes on the pool. You can ignore the pool's master process. 

If one is running (R), it may be the one you want, but there is no warranty for that.

You'll probably have to attach several of them to the debugger before you catch the one you wanted. Begin with the running ones as there are more likely to be the one you look for.

### CLI

Easier than PHP-FPM as they should be fewer and have different arguments making them easier to identify.

```
ari@ari-ThinkPad-T580:~/Documents/perso/ariviere.github.io/_posts$ ps aux | grep php
root     17670  0.0  0.2 621184 36772 ?        Ss   déc.06   0:01 php-fpm: master process (/etc/php/7.2/fpm/php-fpm.conf)
ari      17700  0.0  0.9 671144 158260 ?       S    déc.06   0:07 php-fpm: pool www
ari      17706  0.0  2.6 937588 433748 ?       S    déc.06   0:36 php-fpm: pool www
ari      17708  0.0  0.8 664232 135236 ?       S    déc.06   0:06 php-fpm: pool www
ari      30042 69.2  1.0 582588 165052 tty2    S+   13:35   0:02 /usr/bin/php7.2 /home/ari/dev/myscript --with-options
ari      30056  0.0  0.0  21536  1036 pts/8    S+   13:35   0:00 grep --color=auto php
```

Here, no doubt as I ran a single script, it is PID 30042.

## Attach it to a debugger

I'll use GDB as I'm more familiar with it, but the process should be adaptable to LLDB.

To attach the process, simply run 
```
sudo gdb -p 30042 # replace with the PID you got earlier of course ;)
```

Super user privileges is required to trace a process.

## Get the current function call

In the GDB prompt, type **print executor_globals.current_execute_data**. Congrats, you accessed the top most execute_data PHP is currently executing!

Now let have a look to the member that structure exposes :

```
(gdb) print *executor_globals.current_execute_data
$4 = {
    opline = 0x7fe5002df248, 
    call = 0x0, 
    return_value = 0x7fe519e1f6b0, 
    func = 0x7fe4f8fe2558, 
    This = {
        value = {
	    lval = 140621403335168, 
	    dval = 6.9476204457892199e-310, 
	    counted = 0x7fe4f8cb2a00, 
            str = 0x7fe4f8cb2a00, 
	    arr = 0x7fe4f8cb2a00, 
	    obj = 0x7fe4f8cb2a00, 
	    res = 0x7fe4f8cb2a00, 
	    ref = 0x7fe4f8cb2a00, 
	    ast = 0x7fe4f8cb2a00, 
	    zv = 0x7fe4f8cb2a00, 
	    ptr = 0x7fe4f8cb2a00, 
	    ce = 0x7fe4f8cb2a00, 
            func = 0x7fe4f8cb2a00, 
	    ww = {w1 = 4174064128, w2 = 32740}
	}, 
	u1 = {
	    v = {
	        type = 8 '\b', 
		type_flags = 4 '\004', 
		const_flags = 2 '\002', 
		reserved = 0 '\000'
	    }, 
	    type_info = 132104
	}, 
	u2 = {
	    next = 0, 
            cache_slot = 0, 
	    lineno = 0, 
	    num_args = 0, 
	    fe_pos = 0, 
	    fe_iter_idx = 0, 
	    access_flags = 0, 
	    property_guard = 0, 
	    extra = 0
	}
    }, 
    prev_execute_data = 0x7fe519e1f660,
    symbol_table = 0x1b00000001, 
    run_time_cache = 0x7fe4f8fedb88, 
    literals = 0x7fe5002df228
}
```

* func.common.function_name : The function or method name
* func.common.scope.name : The class name (no matter it is a static method or not)
* This : As you may guess, $this.
* prev_execute_data : The previous execute_data entry of the linked list, useful to build a stack trace.

### String representation

As you may know, strings in C are array of chars, or char \*. When GDB prints a string by itself, it actually doesn't know the variable is an array of characters and only prints the first character.
To make it print the real string, use print (char*) followed by the entry name.

However, don't rely too much on having char \* directly on PHP source code. Strings are zend_string or even smart_str, themselves using zend_string. To get the value of a zend_string, you'll need to access its member "val". 

To wrap that up, to get the function name as string, you'll have to type:
``` 
(gdb) print (char *)executor_globals.current_execute_data.func.common.function_name.val
$14 = 0x7fe4fbb48878 "getName"

(gdb) print (char *)executor_globals.current_execute_data.func.common.scope.name.val
$15 = 0x7fe4fc07ddf0 "Composer\\Package\\BasePackage"
```

### Previous stack entry

Now let get the calling function name:
```
(gdb) print (char *)executor_globals.current_execute_data.prev_execute_data.func.common.scope.name.val
$16 = 0x7fe4fc07ddf0 "Composer\\Package\\BasePackage"

(gdb) print (char *)executor_globals.current_execute_data.prev_execute_data.func.common.function_name.val
$17 = 0x7fe4fc0799b8 "getUniqueName"
```

## How to get a real stack trace?

If you have tens calls in your stack, appending more and more prev_execute_data in the print-s is going to be painful (and even worst when using frameworks that generate interceptors and proxies as Magento does!). But don't worry, we can make macros in GDB!

```
(gdb) define phpbt
Type commands for definition of "phpbt".
End with a line saying just "end".
set $ed=executor_globals.current_execute_data
  while $ed
    print {(char*)((zend_execute_data *)$ed)->func.common.scope.name.val, (char*)((zend_execute_data *)$ed)->func.common.function_name.val}
    set $ed = ((zend_execute_data *)$ed)->prev_execute_data
  end
end
```

And now you can run it:

```
(gdb) phpbt
$48 = {0x7fe4fc07ddf0 "Composer\\Package\\BasePackage", 0x7fe4fbb48878 "getName"}
$49 = {0x7fe4fc07ddf0 "Composer\\Package\\BasePackage", 0x7fe4fc0799b8 "getUniqueName"}
$50 = {0x7fe4fc0794a0 "Composer\\Repository\\ArrayRepository", 0x7fe4fc079a70 "hasPackage"}
$51 = {0x7fe4fc0504d8 "Composer\\Installer\\LibraryInstaller", 0x7fe4fbc04d38 "isInstalled"}
$52 = {0x7fe4fc050408 "Composer\\Installer\\InstallationManager", 0x7fe4fc050700 "isPackageInstalled"}
$53 = {0x7fe4fc04b350 "Composer\\Factory", 0x7fe4fc04f1a8 "purgePackages"}
$54 = {0x7fe4fc04b350 "Composer\\Factory", 0x7fe4fc04f3f0 "createComposer"}
$55 = {0x7fe4fc04b350 "Composer\\Factory", 0x7fe4fbb5ffb8 "create"}
$56 = {0x7fe4fbd57e68 "Magento\\Framework\\Composer\\ComposerFactory", 0x7fe4fbb5ffb8 "create"}
$57 = {0x7fe4fbd08a38 "Magento\\Framework\\Composer\\ComposerInformation", 0x7fe4fc04c0a8 "getComposer"}
$58 = {0x7fe4fbd08a38 "Magento\\Framework\\Composer\\ComposerInformation", 0x7fe4fc04b4f0 "getLocker"}
$59 = {0x7fe4fbd08a38 "Magento\\Framework\\Composer\\ComposerInformation", 0x7fe4fc023080 "getSystemPackages"}
$60 = {0x7fe4fbc34df0 "Magento\\Framework\\App\\ProductMetadata", 0x7fe4fc022df0 "getSystemPackageVersion"}
$61 = {0x7fe4fbc34df0 "Magento\\Framework\\App\\ProductMetadata", 0x7fe4fbb4b6a0 "getVersion"}
$62 = {0x7fe4fbd2d460 "Amasty\\Mostviewed\\Plugin\\View\\Page\\Config\\Renderer", 0x7fe4fc048708 "beforeRenderAssets"}
$63 = {0x7fe4fbdd0698 "Magento\\Framework\\View\\Page\\Config\\Renderer\\Interceptor", 0x7fe4fbe90598 "Magento\\Framework\\Interception\\{closure}"}
$64 = {0x7fe4fbdd0698 "Magento\\Framework\\View\\Page\\Config\\Renderer\\Interceptor", 0x7fe4fbe8fc50 "___callPlugins"}
$65 = {0x7fe4fbdd0698 "Magento\\Framework\\View\\Page\\Config\\Renderer\\Interceptor", 0x7fe4fbf85330 "renderAssets"}
$66 = {0x7fe4fbcaeb18 "Magento\\Framework\\View\\Page\\Config\\Renderer", 0x7fe4fbf82998 "renderHeadContent"}
$67 = {0x7fe4fbdd0698 "Magento\\Framework\\View\\Page\\Config\\Renderer\\Interceptor", 0x7fe4fbf82998 "renderHeadContent"}
$68 = {0x7fe4fbd76f60 "Magento\\Framework\\View\\Result\\Page", 0x7fe4fbb5f468 "render"}
$69 = {0x7fe4fbd76f20 "Magento\\Framework\\View\\Result\\Layout", 0x7fe4fbeb3430 "renderResult"}
$70 = {0x7fe4fbdd0808 "Magento\\Framework\\View\\Result\\Page\\Interceptor", 0x7fe4fbe900c0 "___callParent"}
$71 = {0x7fe4fbdd0808 "Magento\\Framework\\View\\Result\\Page\\Interceptor", 0x7fe4fbe90598 "Magento\\Framework\\Interception\\{closure}"}
$72 = {0x7fe4fbdd0808 "Magento\\Framework\\View\\Result\\Page\\Interceptor", 0x7fe4fbe8fc50 "___callPlugins"}
$73 = {0x7fe4fbdd0808 "Magento\\Framework\\View\\Result\\Page\\Interceptor", 0x7fe4fbeb3430 "renderResult"}
$74 = {0x7fe4fbb94ff0 "Magento\\Framework\\App\\Http", 0x7fe4fbc04820 "launch"}
$75 = {0x7fe4fbdd00c0 "Magento\\Framework\\App\\Http\\Interceptor", 0x7fe4fbc04820 "launch"}
$76 = {0x7fe4fbb94ee0 "Magento\\Framework\\App\\Bootstrap", 0x7fe4fbb95028 "run"}
Cannot access memory at address 0x8
```

Don't worry about the error on the last line, the macro is greatly perfectible, and doesn't handle the case prev_execute_data == NULL.
Actually, there are plenty of situations the macro doesn't handle well: include, require, eval for instance. It may also not support generators and traits correctly but I never tested its behaviour in these situations.

### Making things simple

To avoid typing the macro every time you need it, you can simply create the file _~/.gdbinit_ containing the macro:
```
define phpbt
  set $ed=executor_globals.current_execute_data
  while $ed
    set $scope=((zend_execute_data *)$ed)->func.common.scope
    set $funcname=((zend_execute_data *)$ed)->func.common.function_name.val
    if $scope
      print {(char*)$scope.name.val, (char*)$funcname}
    else
      print {(char*)$funcname}
    end
    set $ed = ((zend_execute_data *)$ed)->prev_execute_data
  end
end
```

## Credits

The GDB macro is an adaptation I made from [wikitech][GDB with PHP], as the original no longer works on PHP7.

[GDB with PHP]: https://wikitech.wikimedia.org/wiki/GDB_with_PHP
