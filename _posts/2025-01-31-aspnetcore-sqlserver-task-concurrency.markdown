---
layout: default
title: "Are .NET concurrent SQL tasks really concurrent?"
description: "Applying .NET Core concurrency principles to SqlConnection and queries can lead to unexpected performances and even timeout" 
excerpt: "Applying .NET Core concurrency principles to SqlConnection and queries can lead to unexpected performances and even timeout" 
date: 2025-01-31 23:00:00 +0200
categories: [AspNetCore]
tags: [AspNetCore, C#, SQLServer, Dapper, performances]
---

# Are .NET concurrent SQL tasks really concurrent?

## Quick recap on .NET Tasks

.NET tasks are executed at creation time : call a method returning a task and it will not wait until await is called to run.\\
If you await your tasks on their creation, your procedural code won't be concurrent (but your host may process several requests simultaneously, and thus still benefit of asynchronous code).\\
If you trigger multiple tasks and await the all later, they'll probably be run concurrently.\\

#### Concurrency or parallelism?

Concurrency means your execution thread will switch from one task to another when an I/O bound operation is performed (file access, network call, delays, ...), however a CPU bound operation won't
be interrupted. CPU intensive tasks without I/O won't benefit of any performance improvement. Worse, async/await cause the compiler to generate state machines that introduce a small overhead, so,
 it's definitly not what your want.\\
Parallelism means multiple threads will handle several execution flows, which permits multiple CPU bound operations to run in the same time.

## A real life issue with Dapper and a single SQL connexion

I encountered some random query timeout in a process that launched multiple batches of SQL queries, each batch consisting of several calls to ExecuteAsync, each awaited. The last query was
a simple UPDATE statement that had to change a single byte on a column of a small table, which was supposed to be instantaneous.\\
I was expecting that all batches would be fully concurrent, at least with a few quantity of batches. However, the last step was sometimes canceled by a SQL timeout after 30 seconds!\\
My first though was my table could have been locked by another process, but it seemed unlikely. I considered my batches to cause an accidental lock. But another idea came to my mind.

## What if there was no concurrency in SQLServer?

Most of SQL engines are able to perform updates in competition from multiple connections. However, I was using a single SqlConnection here. Maybe they were all sharing a single worker process? 
This hypothesis is easy to try. The example codes can even be run without creating any projet using `dotnet script`.

```cs
#r "nuget: Dapper, 2.1.35"
#r "nuget: Microsoft.Data.SqlClient, 5.2.2"

using Dapper;
using Microsoft.Data.SqlClient;

SqlConnection connection1 = new("initial catalog=yyy; data source=192.168.1.106; user=SA; password=xxx; MultipleActiveResultSets=true; Encrypt=false; Application Name=test");
await connection1.OpenAsync();

Task t1 = connection1.ExecuteAsync(@"WAITFOR DELAY '00:00:40';");
await Task.Delay(1);
Task t2 = connection1.ExecuteAsync(@"WAITFOR DELAY '00:00:01';");
await t2;
```

One connexion, two SQL executions. The first one will last for 40 seconds but is never awaited. This code should last for 1 second and 1 millisecond right ?

```
❯ dotnet script .\test.csx
Microsoft.Data.SqlClient.SqlException (0x80131904): Execution Timeout Expired.  The timeout period elapsed prior to completion of the operation or the server is not responding.
 ---> System.ComponentModel.Win32Exception (258): The wait operation timed out.
   at Microsoft.Data.SqlClient.SqlConnection.OnError(SqlException exception, Boolean breakConnection, Action`1 wrapCloseInAction)
   at Microsoft.Data.SqlClient.SqlInternalConnection.OnError(SqlException exception, Boolean breakConnection, Action`1 wrapCloseInAction)
   at Microsoft.Data.SqlClient.TdsParser.ThrowExceptionAndWarning(TdsParserStateObject stateObj, SqlCommand command, Boolean callerHasConnectionLock, Boolean asyncClose)
   at Microsoft.Data.SqlClient.TdsParserStateObject.ThrowExceptionAndWarning(Boolean callerHasConnectionLock, Boolean asyncClose)
   at Microsoft.Data.SqlClient.TdsParserStateObject.CheckThrowSNIException()
   at Microsoft.Data.SqlClient.SqlCommand.CheckThrowSNIException()
   at Microsoft.Data.SqlClient.SqlCommand.InternalEndExecuteNonQuery(IAsyncResult asyncResult, Boolean isInternal, String endMethod)
   at Microsoft.Data.SqlClient.SqlCommand.EndExecuteNonQueryInternal(IAsyncResult asyncResult)
   at Microsoft.Data.SqlClient.SqlCommand.EndExecuteNonQueryAsync(IAsyncResult asyncResult)
   at Microsoft.Data.SqlClient.SqlCommand.<>c.<InternalExecuteNonQueryAsync>b__193_1(IAsyncResult asyncResult)
   at System.Threading.Tasks.TaskFactory`1.FromAsyncCoreLogic(IAsyncResult iar, Func`2 endFunction, Action`1 endAction, Task`1 promise, Boolean requiresSynchronization)
--- End of stack trace from previous location ---
   at Dapper.SqlMapper.ExecuteImplAsync(IDbConnection cnn, CommandDefinition command, Object param) in /_/Dapper/SqlMapper.Async.cs:line 662
   at Submission#0.<<Initialize>>d__0.MoveNext() in C:\code\adelv3\module\Resmed.Module\Resmed.Module.Presentation.Console.Collect\test.csx:line 13
--- End of stack trace from previous location ---
   at Dotnet.Script.Core.ScriptRunner.Execute[TReturn](String dllPath, IEnumerable`1 commandLineArgs) in C:\Users\runneradmin\AppData\Local\Temp\tmp8BAF\Dotnet.Script.Core\ScriptRunner.cs:line 110
ClientConnectionId:593b75bc-5707-4cf2-8600-d76fbdb75f77
Error Number:-2,State:0,Class:11
```

While the first query is executed, the second one is not. By executed, I mean by the SQL engine: .NET ran the ExecuteAsync call, but it got a timeout before being played. Let's retry with two connexions now:

```cs
#r "nuget: Dapper, 2.1.35"
#r "nuget: Microsoft.Data.SqlClient, 5.2.2"

using Dapper;
using Microsoft.Data.SqlClient;

SqlConnection connection1 = new("initial catalog=yyy; data source=192.168.1.106; user=SA; password=xxx; MultipleActiveResultSets=true; Encrypt=false; Application Name=test2");
await connection1.OpenAsync();
Task t1 = connection1.ExecuteAsync(@"WAITFOR DELAY '00:00:40';");
await Task.Delay(1);

SqlConnection connection2 = new("initial catalog=yyy; data source=192.168.1.106; user=SA; password=xxx; MultipleActiveResultSets=true; Encrypt=false; Application Name=test2");
await connection2.OpenAsync();
Task t2 = connection2.ExecuteAsync(@"WAITFOR DELAY '00:00:01';");
await t2;
```

No more error, and  it took two seconds (including the compilation step caused by dotnet script):
```
❯ dotnet script .\test2.csx

```


