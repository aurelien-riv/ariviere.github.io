---
layout: default
title: "Debugging PHP: What is my process waiting for?"
date: 2019-12-28 19:30:00 +0200
categories: [php]
tags: [debugging, strace, procfs, php-src]
---

# How to know what PHP (or any process) is waiting for?

In a previous post, we saw [how to use GDB to get the backtrace of a PHP process on demand][2019-12-07-which-function-php-executing]. If you can use GDB on the machine that is executing PHP, that's great as the information you get are very accurate, but what if you need to understand what's happening on a production server? No doubts your PHP binary will be optimized, debugging symbols will be stripped and your system administrator won't agree to install GDB on that machine. That time, I will propose a way to identify blocking I/O, for PHP or any processes.

These steps will probably only work on Linux systems. It may also work on others Unix-like systems, such as OpenBSD, FreeBSD and so on, with a few adaptations.

## Step 1: get the PID of your process.

First, we use the ps (or pgrep, htop...) command to get the pid of the process we want to debug.

In the [previous post][2019-12-07-which-function-php-executing], I told you to look at the ones that have "R" in their status column, as they are probably the one you want as they are running, contrary to sleeping FPM process that are waiting for a request, even if the process you wanted may also be sleeping at the moment you look at them. Here, we want to know what a process is waiting for, assuming it is waiting for I/O, so our process should be in the "S" state.

```
# ps aux | grep php
www-data   7080  9.9  1.7 507424 140128 ?       S    13:57   0:01 php /var/www/ecommerce/current/bin/magento my:longrunningprocess
root       7089  0.0  0.0  13136  1156 pts/1    S+   13:58   0:00 grep --color=auto php
```

The PID I want is 7080.

## Step 2: Let trace it!

We will use strace to see the system calls made by our process. Here are two captures I made from my process:

```
# strace -p7080
sendto(7, "\234\0\0\0\3SELECT COUNT(*) FROM `catal"..., 160, MSG_DONTWAIT, NULL, 0) = 160
poll([{fd=7, events=POLLIN|POLLERR|POLLHUP}], 1, 86400000) = 1 ([{fd=7, revents=POLLIN}])
recvfrom(7, "\1\0\0\1\1\36\0\0\2\3def\0\0\0\10COUNT(*)\0\f?\0\25\0", 31, MSG_DONTWAIT, NULL, NULL) = 31
poll([{fd=7, events=POLLIN|POLLERR|POLLHUP}], 1, 86400000) = 1 ([{fd=7, revents=POLLIN}])
recvfrom(7, "\0\0\10\201\0\0\0\0\5\0\0\3\376\0\0\"\0\2\0\0\4\0010\5\0\0\5\376\0\0\"\0", 464, MSG_DONTWAIT, NULL, NULL) = 32
nanosleep({tv_sec=5, tv_nsec=0}, 0x7ffd1f974f50) = 0
```

```
# strace -p7080
rt_sigaction(SIGPIPE, {sa_handler=SIG_IGN, sa_mask=[PIPE], sa_flags=SA_RESTORER|SA_RESTART, sa_restorer=0x7f8ad7bdff20}, NULL, 8) = 0
poll([{fd=8, events=POLLIN}], 1, 1000)  = 0 (Timeout)
rt_sigaction(SIGPIPE, NULL, {sa_handler=SIG_IGN, sa_mask=[PIPE], sa_flags=SA_RESTORER|SA_RESTART, sa_restorer=0x7f8ad7bdff20}, 8) = 0
rt_sigaction(SIGPIPE, {sa_handler=SIG_IGN, sa_mask=[PIPE], sa_flags=SA_RESTORER|SA_RESTART, sa_restorer=0x7f8ad7bdff20}, NULL, 8) = 0
poll([{fd=8, events=POLLIN|POLLPRI|POLLRDNORM|POLLRDBAND}], 1, 0) = 0 (Timeout)
rt_sigaction(SIGPIPE, {sa_handler=SIG_IGN, sa_mask=[PIPE], sa_flags=SA_RESTORER|SA_RESTART, sa_restorer=0x7f8ad7bdff20}, NULL, 8) = 0
poll([{fd=8, events=POLLIN}], 1, 1000)  = 0 (Timeout)
rt_sigaction(SIGPIPE, NULL, {sa_handler=SIG_IGN, sa_mask=[PIPE], sa_flags=SA_RESTORER|SA_RESTART, sa_restorer=0x7f8ad7bdff20}, 8) = 0
rt_sigaction(SIGPIPE, {sa_handler=SIG_IGN, sa_mask=[PIPE], sa_flags=SA_RESTORER|SA_RESTART, sa_restorer=0x7f8ad7bdff20}, NULL, 8) = 0
poll([{fd=8, events=POLLIN|POLLPRI|POLLRDNORM|POLLRDBAND}], 1, 0) = 0 (Timeout)
rt_sigaction(SIGPIPE, {sa_handler=SIG_IGN, sa_mask=[PIPE], sa_flags=SA_RESTORER|SA_RESTART, sa_restorer=0x7f8ad7bdff20}, NULL, 8) = 0
poll([{fd=8, events=POLLIN}], 1, 1000)  = 0 (Timeout)
rt_sigaction(SIGPIPE, NULL, {sa_handler=SIG_IGN, sa_mask=[PIPE], sa_flags=SA_RESTORER|SA_RESTART, sa_restorer=0x7f8ad7bdff20}, 8) = 0
rt_sigaction(SIGPIPE, {sa_handler=SIG_IGN, sa_mask=[PIPE], sa_flags=SA_RESTORER|SA_RESTART, sa_restorer=0x7f8ad7bdff20}, NULL, 8) = 0
poll([{fd=8, events=POLLIN|POLLPRI|POLLRDNORM|POLLRDBAND}], 1, 0) = 0 (Timeout)
```

### There are some interesting things to see here:

#### System call names

We can see here some system calls our process invoked:
 - **sendto** and **recvfrom** are used to send and receive data through sockets;
 - **nanosleep** permits to pause the process;
 - **rt\_sigaction** changes the behaviour of the process when some signal occurs;
 - **poll** waits for some event on a file descriptor

You can get more information on these system calls with the command "man 2 " followed by the name of the syscall.

#### System call arguments

After the name of the calls, you can see its arguments. It may seem cryptic but you can extract interesting information there.

For instance, if you look at sendto calls, we can see that its second arguments looks to a truncated SQL query. 

Its first argument is "7" and if you open the manual (man 2 sendto), you see that it is the file descriptor of a socket.

According to the syscalls that are made, other arguments may be interesting, but you may need the manual to know which one are important to you.

### What is wrong with my process?

According to the first capture, PHP sends SQL queries through the socket number 7, and waits for 5 seconds, but on my second capture we can see my process is polling indefinitely on the file descriptor 8. We found the file descriptor that blocks our process, but we still don't know what it represents exactly.

## Step 3: knowing what the file descriptors represent

A file descriptor number is unique **per process**. We now want to identify these fd 7 and 8. There is a directory in the procfs that contains all the file descriptors a process holds: /proc/$pid/fd.

```
# ll /proc/7080/fd/{7,8}
lrwx------ 1 www-data www-data 64 déc.  16 10:59 7 -> 'socket:[39255092]'
lrwx------ 1 www-data www-data 64 déc.  16 10:59 8 -> 'socket:[44820191]'
```

Both file descriptors 7 and 8 are symlinks to a socket. But what are these socket files that appear to be broken links?

## Step 4: resolving the socket id
We now need to get the protocol, the IP address and the port used (if any).

```
# grep -e 39255092 -e 44820191 /proc/net/ -r
/proc/net/tcp:  30: 0271A8C0:DE7C 4E7FD335:01BB 01 00000000:00000000 00:00000000 00000000    33        0 44820191 1 ffff9b3b20de5800 23 4 24 10 7
/proc/net/tcp: 291: 0271A8C0:BD0C 0471A8C0:0CEA 01 00000000:00000000 02:000778D1 00000000    33        0 39255092 2 ffff9b3ceddfb000 20 4 26 2 2
```

File /proc/net/tcp matched, so we know these connections were TCP sockets.

0271A8C0, 8E2FD334 and 0471A8C0 are IP addresses, DE7C, BD0C, 01BB and 0CEA are port numbers. Let turn the hexadecimal IP address values into a more convenient representation:
```
$ printf '%d.%d.%d.%d\n' $(echo 0271A8C0 | sed 's/../0x& /g')
2.113.168.192

$ printf '%d\n' "0x0CEA"
3306
```

Network address are inverted, so 2.113.168.192 means 192.168.113.2.

I had one SQL query on 192.168.113.2:3306, and another tcp connection on 78.127.211.53:443, probably an HTTPS request.

## Step 5: Getting some information related to a network operation
Let ignore the 3306 query, I already know my MySQL server. However, I don't know that IP address.

### State of the connection
There were two IP addresses and ports per line on /proc/net/tcp, the second couple is the remote IP and port, and the first one is local.

To get some information on that connection, using the local port number is easier as it is unique.

DE7C<sub>(16)</sub>=56956<sub>(10)</sub>.

```
$ netstat -nputw | grep 56956
tcp        0      0 192.168.113.2:56956     78.127.211.53:443       ESTABLISHED 7080/php
```

### Reverse DNS resolution
We also can ask for a reverse DNS resolution:
```
$ nslookup 78.127.211.53
78.127.211.53.in-addr.arpa        name = ec2-78.127.211.53.eu-west-1.compute.amazonaws.com.
```

Seems like the service is hosted at AWS... or not as that IP address is a random one ;)

### TLS certificate
We may also get some information from the TLS certificate is the service is using HTTPS:
```
$ openssl s_client 78.127.211.53:443
```

## Conclusion
My process was stuck on waiting for a webservice to reply, but the webservice never replied, as CURLOPT\_TIMEOUT default value is 0, which means never time out from client side. 

Of course, the hints collected here are way less valuable that a backtrace, but still helped to identify the failing function.

[2019-12-07-which-function-php-executing]: {% post_url 2019-12-07-which-function-php-executing %}
