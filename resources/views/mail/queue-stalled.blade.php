A job has been waiting {{ $waitedSeconds }} seconds on the [{{ $connectionName }}] queue, so nothing appears to be consuming it.

Email verification and password reset messages are queued. While this lasts, the app accepts them and reports success to whoever asked, and none of them are delivered.

Check that a queue worker is running on the server, and that the deploy restarts it.
