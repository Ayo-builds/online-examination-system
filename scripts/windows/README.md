# Windows helper scripts

Two batch files for starting and stopping MySQL on a XAMPP machine.

| Script | What it does |
|---|---|
| `mysql_start_clean.bat` | Refuses if mysqld is already running. Otherwise starts it detached, with stdout and stderr pointed at real files in `C:\xampp-backups\mysql-logs\`, then polls `mysqladmin ping` for up to 30 seconds and reports **OK: MySQL is running** or **FAILED to start**. |
| `mysql_shutdown_clean.bat` | Runs `mysqladmin -u root shutdown`, then polls for up to 30 seconds and reports **OK: mysqld exited cleanly**, **MySQL was not running**, or **Shutdown refused**. It never force-kills. |

## Never use the XAMPP Control Panel's MySQL Start or Stop

**Stop** runs `mysql_stop.bat`, which calls `killprocess.bat` on `mysqld.exe` — a
force-kill. A killed mysqld leaves Aria's transaction log control file out of
step with the tables it describes, which shows up later as
`Table is from another system and must be zerofilled or repaired`.

**Start** is the worse of the two. A mysqld started by the Control Panel has no
valid stdout or stderr. On teardown Windows appears to close whatever landed on
descriptors 0-2 — which can be an Aria file — so even an ordinary
`mysqladmin shutdown` fails with:

```
Error writing file 'aria_log_control' (Errcode: 9 "Bad file descriptor")
Aria engine: checkpoint failed
```

Measured on 21 Sep 2026: both Control-Panel-started instances failed their Aria
checkpoint on a normal shutdown, one of them hanging so hard it had to be
killed, and `mysql.db` grew past its expected size each time. Six consecutive
shutdowns of instances started by `mysql_start_clean.bat` were clean, and
`mysql.db` stopped growing. The scripts give mysqld real file handles before it
opens anything else, which is the whole fix.

Apache is unaffected — the Control Panel is fine for that.

## These are copies

The working copies live in `C:\xampp\`, which is where the desktop shortcuts
"Start MySQL cleanly" and "Stop MySQL cleanly" point. The files here are the
versioned originals: after changing one, copy it back to `C:\xampp\`.

They are plain ASCII with CRLF line endings, enforced by `.gitattributes`
(`*.bat text eol=crlf`) — `cmd.exe` mis-parses LF-only batch files.

See `docs/anti-cheat-plan.md`, stage 11, for the school-server checklist,
including installing MySQL as a Windows service and verifying that its
shutdown logs no Aria errors before relying on it.
