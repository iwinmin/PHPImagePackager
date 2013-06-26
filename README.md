PHPImagePackager
================

PHP auto image packager tool

Install
----------------
Requires PHP command-line environment, requires GD2 library extensions.

	### Windows
	copy this files to this directory
		- php.exe
		- php5ts.dll
		- php_gd2.dll

	execute pip.cmd to go

使用方法
----------------
pip S:<resource dir> O:<output file path> [GS:<num>] [GP:<num>] [GB:<num>]
	[CC:<css class>] [CF:<css file path>] [CS:(1|0)] [LF:<list file name>]

	<b>S:<resource dir></b> <i>必须</i>
	需要打包的图片资源所在的目录. 脚步会扫描该目录下的所有子目录, 寻找文件名格式
	符合要求的所有图片文件.
	例如: pip S:/icon/dir/name

	<b>O:<output file path></b> <i>必须</i>
	合并后的输出文件路径, 必须指定图片扩展名来确定要输出的文件格式. 支持下面的三
	种扩展名 png, jpg, gif.
	like: pip O:merged-icon.png


Usage
----------------
pip S:<resource dir> O:<output file path> [GS:<num>] [GP:<num>] [GB:<num>]
	[CC:<css class>] [CF:<css file path>] [CS:(1|0)] [LF:<list file name>]

	<b>S:<resource dir></b> <i>required</i>
	The resources directory need to package.
	like: pip S:/icon/dir/name

	<b>O:<output file path></b> <i>required</i>
	Merged image file path, you need to specify the file extension to determine
	the output format.
	like: pip O:merged-icon.png


