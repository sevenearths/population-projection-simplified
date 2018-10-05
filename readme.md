NOTE: This project highlights my ability to work with complex multi-dimensional arrays. It requires base data for another database to do its calculations on so does not work at this time

```
[root@pc population-projection-simplified]# vi .env
...
INTERNATIONAL_IN_HELD_CONSTANT     = [false/true]
CROSS_BORDER_IN_HELD_CONSTANT      = [false/true]
BIRTHS_BY_AGE_OF_MOTHER_MULTIPLIER = ...
DEATHS_MULTIPLIER                  = ...
INTERNAL_IN_MULTIPLIER             = ...
INTERNAL_OUT_MULTIPLIER            = ...
INTERNATIONAL_IN_MULTIPLIER        = ...
INTERNATIONAL_OUT_MULTIPLIER       = ...
CROSS_BORDER_IN_MULTIPLIER         = ...
CROSS_BORDER_OUT_MULTIPLIER        = ...
...
[root@pc population-projection-simplified]# ls -la storage/exports/
total 5
drwxrwxrwx 2 root root 4096 Jan 01 12:00 .
drwxrwxrwx 2 root root   64 Jan 01 12:00 ..
[root@pc population-projection-simplified]# php artisan project:projection snpp_2012 stockton 2013 2036
Database name is in the correct format
Local authority data for 'stockton' was found in Hugo's database
+------------------------------------+-------+
| Variable                           | Value |
+------------------------------------+-------+
| International In Held Constant     | true  |
| Cross Border In Held Constant      | true  |
| Births By Age Of Mother Multiplier | 1.0   |
| Deaths Multiplier                  | 1.0   |
| Internal In Multiplier             | 1.0   |
| Internal Out Multiplier            | 1.0   |
| International In Multiplier        | 1.0   |
| International Out Multiplier       | 1.0   |
| Cross Border In Multiplier         | 1.0   |
| Cross Border Out Multiplier        | 1.0   |
+------------------------------------+-------+

 Are you happy with these values for the projection? (yes/no) [no]:
 > y

 1977/1977 [▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓] 100%
File written to => '.../.../population-projection-simplified/storage/export/197001011200_stockton_projection.xls'

-------------------------------------------
The process took 50 seconds
-------------------------------------------
[root@pc population-projection-simplified]# php artisan project:projection snpp_2012 stockton 2013 2036 --no-questions
Database name is in the correct format
Local authority data for 'stockton' was found in Hugo's database
 1977/1977 [▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓] 100%
File written to => '.../.../population-projection-simplified/storage/export/197001011201_stockton_projection.xls'

-------------------------------------------
The process took 43 seconds
-------------------------------------------
[root@pc population-projection-simplified]# ls -la storage/exports/
total 1569
drwxrwxrwx 2 root root   4096 Jan 01 12:00 .
drwxrwxrwx 2 root root     64 Jan 01 12:00 ..
-rw-rw-r-- 1 root root 799232 Jan 01 12:00 197001011200_stockton_projection.xls
-rw-rw-r-- 1 root root 799232 Jan 01 12:00 197001011201_stockton_projection.xls
[root@pc population-projection-simplified]#
```
