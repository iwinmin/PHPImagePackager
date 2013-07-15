<?php
define('IMG_WIDTH', 0);
define('IMG_HEIGHT', 1);
define('IMG_TYPE', 2);

define('IMG_RIGHT', 2);
define('IMG_BOTTOM', 3);
define('LIST_NORMAL', 4);
define('LIST_RIGHT', 5);
define('LIST_BOTTOM', 6);
define('LIST_AUTO', 7);
define('FILE_PATTERN', '/^([rbgadtz])((?:,\d+){0,4})(\[[,\-\d]+\])?(\([^\)]+\))?(\{[^\}]+\})?$/i');
define('LIST_FILE', 'list.txt');
define('FAKE_FILE', '@null');
define('CWD', str_replace("\\", "/", getcwd()).'/');

// 扫描目录
$cache = array();
function scan_dir($dir, &$list){
	global $cache;
	$dh = opendir($dir);
	while ($file = readdir($dh)){
		if ($file == '.' || $file == '..') continue;
		$path = realpath($dir . '/' . $file);
		if (is_dir($path)){
			scan_dir($path, $list);
		}else {
			$pos = strrpos($file, '.');
			if ($pos === 0){
				$file = substr($file, 1);
			}elseif ($pos !== false){
				$file = substr($file, 0, $pos);
			}

			if (!preg_match(FILE_PATTERN, $file, $ms)) continue;
			if ($ms[1] == 'd') continue; // 真实文件不允许占位类型
			$info = $cache[$path] = getimagesize($path);
			if (!$info) continue;
			$list[] = array(
				'width' => $info[IMG_WIDTH],
				'height' => $info[IMG_HEIGHT],
				'top' => 0,
				'left' => 0,
				'type' => $info[IMG_TYPE],
				'name' => $ms,
				'path' => $path
			);
		}
	}
	closedir($dh);
}

function to_real_path($path, $base){
	if (empty($path)){ return NULL; }
	if ($path == FAKE_FILE) return $path;

	if ($path[0] != '/' && $path[0] != "\\" && $path[1] != ':'){
		$path = $base . '/' . $path;
	}
	$path = realpath($path);
	if ($path){
		return str_replace("\\", "/", $path);
	}else {
		return NULL;
	}
}

// 处理配置文件
function parse_list($path, &$list){
	if (!file_exists($path)) return;

	global $cache;
	$lines = file($path);
	$base  = dirname($path);
	$zone_file = NULL;
	$last  = NULL;
	foreach ($lines as $line){
		$line = preg_split('/[ \t]+/', trim($line));
		$name = array_shift($line);
		if (empty($name) || $name[0] === '#') continue;

		if ($name[0] === '['){
			$zone_file = to_real_path(substr($name, 1, -1), $base);
			continue;
		}
		while (1){
			$path = array_shift($line);
			switch ($path[0]) {
				case '[':
				case '(':
				case '{':
					$name .= $path;
				break;
				case '#':
					$path = $zone_file;
				break 2;
				default:
					$path = to_real_path($path, $base);
				break 2;
			}
		}
		if (!$path && $zone_file){ $path = $zone_file; }

		if (empty($path) || !preg_match(FILE_PATTERN, $name, $ms)) continue;
		if ($last){
			$copy = $last;
			switch ($ms[1]) {
				case 't':
					$copy['type'] = FAKE_FILE;
				case 'd':
					if (isset($ms[4])) $copy['name'][4] = $ms[4];
					if (isset($ms[5])) $copy['name'][5] = $ms[5];
					$copy['path'] = $path;
					$list[] = $copy;
				continue 2;
			}
		}

		if ($path == FAKE_FILE){
			$info = array(
				IMG_WIDTH => 0,
				IMG_HEIGHT => 0,
				IMG_TYPE => FAKE_FILE
			);
		}else {
			if (!isset($cache[$path])){
				$cache[$path] = getimagesize($path);
			}
			$info = $cache[$path];
			if (!$info) continue;
		}

		$list[] = $last = array(
			'width' => $info[IMG_WIDTH],
			'height' => $info[IMG_HEIGHT],
			'_w' => $info[IMG_WIDTH],
			'_h'=> $info[IMG_HEIGHT],
			'top' => 0,
			'left' => 0,
			'type' => $info[IMG_TYPE],
			'name' => $ms,
			'path' => $path
		);
	}
}

// 合并文件方法
function merge_image($im, $info){
	if ($info['type'] == FAKE_FILE) return;

	static $last_path=false, $src=false;
	if ($src && $last_path != $info['path']){
		imagedestroy($src);
		$src = false;
	}

	if (!$src){
		$last_path = $info['path'];
		echo "LOAD: {$last_path}\n";
		switch ($info['type']) {
			case IMAGETYPE_PNG:
				$src = imagecreatefrompng($last_path);
				break;
			case IMAGETYPE_GIF:
				$src = imagecreatefromgif($last_path);
				break;
			case IMAGETYPE_JPEG:
				$src = imagecreatefromjpeg($last_path);
				break;
			default:
				return false;
		}
		if (!$src) return false;
	}
	$left = $info['left'];
	if ($left < 0){
		$info['dx'] -= $left;
		$info['width'] += $left;
		$left = 0;
	}
	$top  = $info['top'];
	if ($top < 0){
		$info['dy'] -= $top;
		$info['height'] += $top;
		$top = 0;
	}
	$info['width'] = min($info['width'], $info['_w'] - $left);
	$info['height'] = min($info['height'], $info['_h'] - $top);

	imagecopy($im, $src, $info['dx'], $info['dy'], $left, $top, $info['width'], $info['height']);
}

function parse_path($path){
	$path = str_replace("\\", "/", $path);
	if ($path[0] != '/' && $path[1] != ':'){
		$path = CWD . $path;
	}
	$paths = explode('/', $path);
	$out = array();
	foreach ($paths as $path){
		switch ($path) {
			case '..':
				array_pop($out);
			case '.':
			case '':
				break;

			default:
				$out[] = $path;
				break;
		}
	}
	return $out;
}

// 生成CSS代码
function gen_css($info, $opt = NULL){
	static $code='', $enable=false, $prefix, $size, $id, $less, $url;
	if ($info === 'init'){
		$enable = !!$opt['output'];
		if ($enable){
			$out = parse_path($opt['output']);
			$img = parse_path($opt['path']);
			while ($out[0] == $img[0]){
				array_shift($out);
				array_shift($img);
			}

			$id = 0;
			$prefix = $opt['prefix'];
			$size = $opt['size'];
			$less = (strtolower(substr($opt['output'], -5)) == '.less') ? '()':'';
			$name = str_repeat('../', count($out)-1) . implode('/', $img);
			$url = $opt['url'] ? $name : false;
			$code = "\n/* Image: {$name} */";
			if (!$url){
				$code .= "\n.{$prefix}{$less} {background-image:url('{$name}'); background-repeat: no-repeat;}";
			}
		}
	}elseif ($info === 'result'){
		return $enable ? $code : '';
	}elseif ($enable){
		$id++;
		if ($info['type'] == FAKE_FILE) return; // 透明不生成样式
		if (isset($info['note']) && preg_match('/^[a-z0-9\-_]+$/i', $info['note'])){
			$name = $info['note'];
		}else {
			$name = $id;
		}
		$code .= "\n.{$prefix}-{$name}{$less} {";
		if ($url){
			$code .= "background:url('{$url}') no-repeat ";
		}else {
			$code .= "background-position:";
		}
		$x = $info['dx'] > 0 ? "-{$info['dx']}px" : 0;
		$y = $info['dy'] > 0 ? "-{$info['dy']}px" : 0;
		switch ($info['mode']){
			case 'r':
				$code .= "right {$x};";
				break;
			case 'b':
				$code .= "{$y} bottom;";
				break;
			default:
				$code .= "{$y} {$x};";
				break;
		}
		if ($size){
			$code .= " width:{$info['width']}px; height:{$info['height']}px;";
		}
		$code .= '}';
	}
}

/**
File Name Format:
靠右定位
r,<top>,<paddingLeft>,<paddingRight>[<cropWidth>,<cropHeight>,<cropX>,<cropY>](prefix){name}
靠底定位
b,<left>,<paddingTop>,<paddingBottom>[<cropWidth>,<cropHeight>,<cropX>,<cropY>](prefix){name}
栅格定位
g,<col>,<row>,<gridSize>[<cropWidth>,<cropHeight>,<cropX>,<cropY>](prefix){name}
绝对坐标定位
a,<X>,<Y>[<cropWidth>,<cropHeight>,<cropX>,<cropY>](prefix){name}
自动排列
z[<cropWidth>,<cropHeight>,<cropX>,<cropY>](prefix){name}
复制上一个定义
d(prefix){name}
复制空项目
t(prefix)
**/
function run(){
	// 默认参数
	$SOURCE    = ''; // 源文件目录
	$OUTPUT	   = ''; // 输出文件地址
	$GRID_SIZE = 20; // 栅格大小(像素)
	$CSS_CLASS = 'icon'; // CSS类前续
	$CSS_FILE  = ''; // CSS导出文件地址
	$CSS_SIZE  = true; // 导出图片的块大小CSS属性
	$CSS_URL   = true; // 导出CSS包含URL地址
	$GRID_PAD  = 0; // 自动排列时图片间隔
	$GRID_BLK  = 0; // 自动排列最少块高宽
	$LIST_FILE = LIST_FILE;

	// 分析参数
	$argv = func_get_args();
	$len = func_num_args();
	for ($i=1; $i<$len; $i++){
		$param = explode(':', $argv[$i], 2);
		switch (strtoupper($param[0])){
			case 'S':
				$SOURCE = str_replace("\\", "/", $param[1]);
				break;
			case 'O':
				$OUTPUT = str_replace("\\", "/", $param[1]);
				break;
			case 'GS':
				$GRID_SIZE = max(0, intval($param[1]));
				break;
			case 'GP':
				$GRID_PAD = max(0, intval($param[1]));
				break;
			case 'GB':
				$GRID_BLK = max(0, intval($param[1]));
				break;
			case 'CC':
				$CSS_CLASS = trim($param[1]);
				if (empty($CSS_CLASS)) $CSS_CLASS = 'icon';
				break;
			case 'CF':
				$CSS_FILE = str_replace("\\", "/", $param[1]);
				break;
			case 'CS':
				$CSS_SIZE = ($param[1] == '1');
				break;
			case 'CU':
				$CSS_URL = ($param[1] == '1');
				break;
			case 'LF':
				$LIST_FILE = str_replace("\\", "/", $param[1]);
				break;
		}
	}

	// 检查参数
	if (!is_dir($SOURCE)){
		die("ERROR: SOURCE IS NOT DIRECTORY!\n");
	}
	$OUTPUT_EXT = $ext = strrchr($OUTPUT, '.');
	$ext = strtolower($ext);
	switch ($ext) {
		case '.png':
		case '.jpg':
		case '.gif':
		break;

		default:
			die("ERROR: OUTPUT FILE FORMAT UNSUPPORT!\n");
		break;
	}

	// 输出参数状态
	echo 'SOURCE DIR : '.$SOURCE."\n";
	echo 'OUTPUT FILE: '.$OUTPUT."\n";
	echo 'GRID SIZE  : '.$GRID_SIZE."\n";
	if ($CSS_FILE){
		echo 'CSS FILE   : '.$CSS_FILE."\n";
		echo 'CSS PREFIX : '.$CSS_CLASS."\n";
		echo 'CSS SIZE   : '.($CSS_SIZE ? 'include' : 'not include')."\n";
		echo 'CSS URL    : '.($CSS_URL ? 'include' : 'not include')."\n";
	}
	echo "==========================================\n";

	// 遍历文件
	$list = array();
	scan_dir($SOURCE, $list);

	// 检查有没有配置列表文件
	parse_list($SOURCE . '/' . $LIST_FILE, $list);

	// 计算图片位置和输出图片大小
	$empty = array(0, 0, 0, 0, array(), array(), array(), array());
	// 默认无后续名记录
	$outs = array('' => $empty);

	// 分析文件名参数
	$len = count($list);
	for ($i=0; $i<$len; $i++){
		$img = &$list[$i];
		$ms = $img['name'];

		// 合并模式
		// r - 靠右
		// b - 靠下
		// g - 表格定位
		// a - 绝对定位
		$mode = $img['mode'] = strtolower($ms[1]);

		// 过滤备注信息
		if (!empty($ms[5])){
			$img['note'] = substr($ms[5], 1, -1);
		}

		// 状态后续名
		if (!empty($ms[4])){
			$cat = substr($ms[4], 1, -1);
			if (!isset($outs[$cat])){
				$outs[$cat] = $empty;
			}
		}else {
			$cat = '';
		}

		// 切割参数
		if (!empty($ms[3])){
			$crop = explode(',', substr($ms[3],1,-1)); // w.h.l.t
			switch (count($crop)) {
				case 4:
					$img['top'] = intval($crop[3]);
				case 3:
					$img['left'] = intval($crop[2]);
				case 2:
					$img['height'] = max(0, intval($crop[1]));
				case 1:
					$img['width'] = max(0, intval($crop[0]));
			}
		}

		$pos = explode(',', $ms[2]);
		$out = &$outs[$cat];
		$oh = $ow = $or = $ob = 0;
		switch ($mode) {
			case 'r':
				// y.padLeft.padRight
				$dx = $img['width'];
				$dy = intval($pos[1]);
				if (isset($pos[3])){
					$dx += intval($pos[3]);
				}
				$pad = isset($pos[2]) ? intval($pos[2]) : 0;
				$or = $dx + $pad;
				$oh = $dy + $img['height'];
				$out[LIST_RIGHT][] = &$img;
			break;

			case 'b':
				// y.padTop.padBottom
				$dx = intval($pos[1]);
				$dy = $img['height'];
				if (isset($pos[3])){
					$dy += intval($pos[3]);
				}
				$pad = isset($pos[2]) ? intval($pos[2]) : 0;
				$ob = $dy + $pad;
				$ow = $dx + $img['width'];
				$out[LIST_BOTTOM][] = &$img;
			break;

			case 'z':
				$dx = $dy = 0;
				$out[LIST_AUTO][] = &$img;
			break;

			default:
				$dx = intval($pos[1]);
				$dy = intval($pos[2]);
				if ($mode == 'g'){
					$size = isset($pos[3]) ? intval($pos[3]) : $GRID_SIZE;
					$dx *= $size;
					$dy *= $size;
				}
				$ow = $dx + $img['width'];
				$oh = $dy + $img['height'];
				$out[LIST_NORMAL][] = &$img;
			break;
		}
		if ($or > $out[IMG_RIGHT]) $out[IMG_RIGHT] = $or;
		if ($ob > $out[IMG_BOTTOM]) $out[IMG_BOTTOM] = $ob;
		if ($ow > $out[IMG_WIDTH]) $out[IMG_WIDTH] = $ow;
		if ($oh > $out[IMG_HEIGHT]) $out[IMG_HEIGHT] = $oh;
		$out['cat'] = $cat;
		$img['dx'] = $dx;
		$img['dy'] = $dy;
		unset($img, $out);
	}

	// 处理自动布局部分列表
	foreach ($outs as &$out) {
		if (count($out[LIST_AUTO]) == 0) continue;
		$m = 'h';
		$w = $out[IMG_WIDTH];
		$h = $y = $out[IMG_HEIGHT];
		$x = 0;
		foreach ($out[LIST_AUTO] as &$img) {
			if (!$m){
				if ($w >= $h){
					$m = 'h';
					$x = 0;
					$y = $h + $GRID_PAD;
				}else {
					$m = 'v';
					$y = 0;
					$x = $w + $GRID_PAD;
				}
			}
			$img['dx'] = $x;
			$img['dy'] = $y;
			$bw = $img['width'];
			$bh = $img['height'];
			if ($GRID_BLK){
				if ($bw < $GRID_BLK) $bw = $GRID_BLK;
				if ($bh < $GRID_BLK) $bh = $GRID_BLK;
			}
			if ($m == 'h'){
				$x += $bw + $GRID_PAD;
				$h = max($h, $y + $bh);
			}else {
				$y += $bh + $GRID_PAD;
				$w = max($w, $x + $bw);
			}
			if ($m == 'h'){
				if ($x >= $w){
					$m = false;
					$w = $x - $GRID_PAD;
				}
			}else{
				if ($y >= $h){
					$m = false;
					$h = $y - $GRID_PAD;
				}
			}
			unset($img);
		}
		$out[IMG_WIDTH] = max($out[IMG_WIDTH], $w, $x-$GRID_PAD);
		$out[IMG_HEIGHT] = max($out[IMG_HEIGHT], $h, $y-$GRID_PAD);
		unset($out);
	}

	$css = "/*** \n * Background Image CSS auto generate by PHP Image Packager";
	$css .= "\n * http://blog.win-ing.cn by Katana\n ***/";
	// 生成文件
	foreach ($outs as $cat => &$out) {
		$width = $out[IMG_WIDTH] + $out[IMG_RIGHT];
		$height = $out[IMG_HEIGHT] + $out[IMG_BOTTOM];
		if ($width <= 0 || $height <= 0) continue;

		$path = $OUTPUT;
		if ($cat != ''){
			$path = substr($path, 0, -strlen($ext)) . '_' . $cat . $OUTPUT_EXT;
		}

		$im = imageCreateTrueColor($width, $height);
		imageAlphaBlending($im, FALSE); //取消默认的混色模式
		imageSaveAlpha($im, TRUE); //设定保存完整的 alpha 通道信息
		$bg = imageColorAllocateAlpha($im, 0, 0, 0, 127);
		imageFilledRectangle($im, 0, 0, $width, $height, $bg);

		$prefix = ($cat == '' ? $CSS_CLASS : $CSS_CLASS.'-'.$cat);
		$opt = array(
			'prefix' => $prefix,
			'url' => $CSS_URL,
			'size' => $CSS_SIZE,
			'path' => $path,
			'output' => $CSS_FILE
		);
		gen_css('init', $opt);

		foreach ($out[LIST_NORMAL] as $img) {
			gen_css($img);
			merge_image($im, $img);
		}
		foreach ($out[LIST_RIGHT] as $img) {
			gen_css($img);
			$img['dx'] = $width - $img['dx'];
			merge_image($im, $img);
		}
		foreach ($out[LIST_BOTTOM] as $img) {
			gen_css($img);
			$img['dy'] = $height - $img['dy'];
			merge_image($im, $img);
		}
		foreach ($out[LIST_AUTO] as $img) {
			gen_css($img);
			merge_image($im, $img);
		}

		if ($CSS_FILE){
			$css .= gen_css('result');
		}

		switch ($ext) {
			case '.gif':
				imagegif($im, $path);
				break;
			case '.png':
				imagepng($im, $path);
				break;
			case '.jpg':
				imagejpeg($im, $path);
				break;
		}
		imageDestroy($im);
		echo "OUTPUT: {$path}\n";
	}
	if ($CSS_FILE){
		echo "CSS FILE: {$CSS_FILE}\n";
		file_put_contents($CSS_FILE, $css);
	}
}


echo "\n==========================================\n";
echo "# PHP Image Package Script  v0.1         #\n";
echo "#         Katana  http://blog.win-ing.cn #\n";
echo "==========================================\n";

call_user_func_array('run', $argv);
?>