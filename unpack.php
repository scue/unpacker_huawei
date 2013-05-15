<?php

function usage($argv) {
	printf("usage: php %s -u <UPDATE.APP> <DIRECTORY>\n", $argv[0]);
	printf("         extracts firmware into current directory.\n");
	printf("       php %s -r <DIRECTORY> <UPDATE.APP.NEW>\n", $argv[0]);
	printf("         packs extracted firmware into UPDATE.APP format.\n");
	exit;
}

define('STATE_READ_SKIP', 0);
define('STATE_READ_HEAD', 1);
define('STATE_READ_BODY', 2);

function readle32($str, $off) {
	$r = 0;
	for ($i = 0; $i < 4; $i++) {
		$c = ord($str[$off + 3 - $i]);
		$r = $r << 8;
		$r = $r | $c;
		$r = $r & 0xffffffff;
	}
	return $r;
}

function readle16($str, $off) {
	$r = ord($str[$off + 1]);
	$r <<= 8;
	$r |= ord($str[$off]);
	return $r;
}

function writele32(&$str, $off, $val) {
    $str[$off] = chr($val & 0xff);
    $str[$off + 1] = chr(($val >> 8) & 0xff);
    $str[$off + 2] = chr(($val >> 16) & 0xff);
    $str[$off + 3] = chr(($val >> 24) & 0xff);
}

function writele16(&$str, $off, $val) {
    $str[$off] = chr($val & 0xff);
    $str[$off + 1] = chr(($val >> 8) & 0xff);
}

function string_fill($c, $n) {
    $r = '';
    for ($i = 0; $i < $n; $i++) {
        $r .= $c;
    }
    return $r;
}

$crc16_table = array(
	0x0000, 0x1189, 0x2312, 0x329B, 0x4624, 0x57AD, 0x6536, 0x74BF,
	0x8C48, 0x9DC1, 0xAF5A, 0xBED3, 0xCA6C, 0xDBE5, 0xE97E, 0xF8F7,
	0x1081, 0x0108, 0x3393, 0x221A, 0x56A5, 0x472C, 0x75B7, 0x643E,
	0x9CC9, 0x8D40, 0xBFDB, 0xAE52, 0xDAED, 0xCB64, 0xF9FF, 0xE876,
	0x2102, 0x308B, 0x0210, 0x1399, 0x6726, 0x76AF, 0x4434, 0x55BD,
	0xAD4A, 0xBCC3, 0x8E58, 0x9FD1, 0xEB6E, 0xFAE7, 0xC87C, 0xD9F5,
	0x3183, 0x200A, 0x1291, 0x0318, 0x77A7, 0x662E, 0x54B5, 0x453C,
	0xBDCB, 0xAC42, 0x9ED9, 0x8F50, 0xFBEF, 0xEA66, 0xD8FD, 0xC974,
	0x4204, 0x538D, 0x6116, 0x709F, 0x0420, 0x15A9, 0x2732, 0x36BB,
	0xCE4C, 0xDFC5, 0xED5E, 0xFCD7, 0x8868, 0x99E1, 0xAB7A, 0xBAF3,
	0x5285, 0x430C, 0x7197, 0x601E, 0x14A1, 0x0528, 0x37B3, 0x263A,
	0xDECD, 0xCF44, 0xFDDF, 0xEC56, 0x98E9, 0x8960, 0xBBFB, 0xAA72,
	0x6306, 0x728F, 0x4014, 0x519D, 0x2522, 0x34AB, 0x0630, 0x17B9,
	0xEF4E, 0xFEC7, 0xCC5C, 0xDDD5, 0xA96A, 0xB8E3, 0x8A78, 0x9BF1,
	0x7387, 0x620E, 0x5095, 0x411C, 0x35A3, 0x242A, 0x16B1, 0x0738,
	0xFFCF, 0xEE46, 0xDCDD, 0xCD54, 0xB9EB, 0xA862, 0x9AF9, 0x8B70,
	0x8408, 0x9581, 0xA71A, 0xB693, 0xC22C, 0xD3A5, 0xE13E, 0xF0B7,
	0x0840, 0x19C9, 0x2B52, 0x3ADB, 0x4E64, 0x5FED, 0x6D76, 0x7CFF,
	0x9489, 0x8500, 0xB79B, 0xA612, 0xD2AD, 0xC324, 0xF1BF, 0xE036,
	0x18C1, 0x0948, 0x3BD3, 0x2A5A, 0x5EE5, 0x4F6C, 0x7DF7, 0x6C7E,
	0xA50A, 0xB483, 0x8618, 0x9791, 0xE32E, 0xF2A7, 0xC03C, 0xD1B5,
	0x2942, 0x38CB, 0x0A50, 0x1BD9, 0x6F66, 0x7EEF, 0x4C74, 0x5DFD,
	0xB58B, 0xA402, 0x9699, 0x8710, 0xF3AF, 0xE226, 0xD0BD, 0xC134,
	0x39C3, 0x284A, 0x1AD1, 0x0B58, 0x7FE7, 0x6E6E, 0x5CF5, 0x4D7C,
	0xC60C, 0xD785, 0xE51E, 0xF497, 0x8028, 0x91A1, 0xA33A, 0xB2B3,
	0x4A44, 0x5BCD, 0x6956, 0x78DF, 0x0C60, 0x1DE9, 0x2F72, 0x3EFB,
	0xD68D, 0xC704, 0xF59F, 0xE416, 0x90A9, 0x8120, 0xB3BB, 0xA232,
	0x5AC5, 0x4B4C, 0x79D7, 0x685E, 0x1CE1, 0x0D68, 0x3FF3, 0x2E7A,
	0xE70E, 0xF687, 0xC41C, 0xD595, 0xA12A, 0xB0A3, 0x8238, 0x93B1,
	0x6B46, 0x7ACF, 0x4854, 0x59DD, 0x2D62, 0x3CEB, 0x0E70, 0x1FF9,
	0xF78F, 0xE606, 0xD49D, 0xC514, 0xB1AB, 0xA022, 0x92B9, 0x8330,
	0x7BC7, 0x6A4E, 0x58D5, 0x495C, 0x3DE3, 0x2C6A, 0x1EF1, 0x0F78
);

function update_app_crc16($data, $size) {
    global $crc16_table;
    $sum = 0xffff;
    $i = 0;
    while ($size >= 8) {
    	$v = ord($data[$i++]);
    	$sum = ($crc16_table[($v ^ $sum) & 0xff] ^ ($sum >> 8)) & 0xffff;
    	$size -= 8;
    }
    if ($size) {
    	for ($n = $data[$i] << 8; ; $n >>= 1) {
    		if ($size == 0) break;
    		$size -= 1;
    		$flag = (($sum ^ $n) & 1) == 0;
    		$sum >>= 1;
    		if ($flag) $sum ^= 0x8408;
    	}
    }
    return ($sum ^ 0xffff) & 0xffff;
}

function unpack_update_app($f, $d) {
	$state = STATE_READ_SKIP;
	$finished = false;
	$index = 0;
	$offset = 0;
	$test = 0;
	$fp = fopen($f, "rb");
	if (!$fp) return false;
	while (-1) {
		if ($state == STATE_READ_SKIP) {
			$c = fgetc($fp);
			if ($c == false) break;
			$offset += 1;
			$test = $test << 8;
			$test = $test & 0xffffff00;
			$test = $test | ord($c);
			if ($test == 0x55aa5aa5) {
				if (fseek($fp, -4, SEEK_CUR) < 0)
					break;
				$offset -= 4;
				$state = STATE_READ_HEAD;
				continue;
			}
		} else if ($state == STATE_READ_HEAD) {
			// 4 magic
			// 4 head size
			// 4 version
			// 8 hardware
			// 4 unknown
			// 4 body size
			// 16 date
			// 16 time
			// 32 INPUT
			// 2 ?
			// 4
			// 2 * N
			// padding to 4 aligned
			$h = fread($fp, 98);
			if (strlen($h) != 98) break;
			$hsize = readle32($h, 4);
			$bsize = readle32($h, 24);
			if (($bsize & 4095) != 0)
				$tsize = (1 + ($bsize >> 12)) * 2;
			else
				$tsize = ($bsize >> 12) * 2;
			if ($hsize != 98 + $tsize) break;
			$table = fread($fp, $tsize);
			if (strlen($table) != $tsize) break;
			printf("writing `file_%02d', offset = %08x, hsize = %08x, bsize = %08x...\n", $index, $offset, $hsize, $bsize);
			$offset += (98 + $tsize);
			$fn = sprintf("%s/file_%02d.head", $d, $index);
			$out = fopen($fn, "wb");
			if (!$out) break;
			$rc = fwrite($out, $h);
			if ($rc != strlen($h)) {
				fclose($out);
				break;
			}
			$rc = fwrite($out, $table);
			if ($rc != strlen($table)) {
				fclose($out);
				break;
			}
			fclose($out);
			$state = STATE_READ_BODY;
			continue;		
		} else if ($state == STATE_READ_BODY) {
			$fn = sprintf("%s/file_%02d.body", $d, $index);
			$out = fopen($fn, "wb");
			if (!$out) break;
			$n = 0;
			while ($n < $bsize) {
				$t = $bsize - $n;
				if ($t > 4096) $t = 4096;
				$l = fread($fp, $t);
				if (strlen($l) != $t) break;
				$n += $t;
				$offset += $t;
				$rc = fwrite($out, $l);
				if ($rc != $t) break;
			}
			fclose($out);
			if ($n != $bsize) break;
			if ($offset & 3) {
				$t = 4 - ($offset & 3);
				$l = fread($fp, $t);
				if (strlen($l) != $t) break;
				$offset += $t;
			}
			$state = STATE_READ_HEAD;
			$index += 1;
			continue;
		}
	}
	$finished = feof($fp);
	fclose($fp);
	return $finished;
}

function repack_update_app($dir, $target) {
	$offset = 0;
	$index = 0;
	$fp = fopen($target, "wb");
	if (!$fp) return false;
	$zero = string_fill("\0", 0x5c);
	$rc = fwrite($fp, $zero, 0x5c);
	if ($rc != 0x5c) {
		fclose($fp);
		return false;
	}
	$offset += 0x5c;
	while (-1) {
		$hf = sprintf("%s/file_%02d.head", $dir, $index);
		$bf = sprintf("%s/file_%02d.body", $dir, $index);
		if (!is_file($hf) || !is_file($bf)) break;
		// DON'T EDIT head file unless you know what you are doing
		$_h = file_get_contents($hf);
		$h = substr($_h, 0, 98);
		if (strlen($h) != 98) {
			fclose($fp);
			return false;
		}
		$bsize = filesize($bf);
		// DON'T ACCEPT empty file
		if ($bsize == 0) {
			fclose($fp);
			return false;		
		}
		// rebuild head #1
		$_bsize = readle32($h, 24);
		if ($bsize != $_bsize)
			writele32($h, 24, $bsize);
		if (($bsize & 4095) != 0)
			$tsize = (1 + ($bsize >> 12)) * 2;
		else
			$tsize = ($bsize >> 12) * 2;
		$table = string_fill("\0", $tsize);
		$_hsize = readle32($h, 4);
		$hsize = 98 + $tsize;
		if ($_hsize != $hsize)
			writele32($h, 4, $hsize);
		printf("adding file_%02d, offset = %08x, hsize = %08x, bsize = %08x\n", $index, $offset, $hsize, $bsize);
		// write head
		$rc = fwrite($fp, $h, 98);
		if ($rc != 98) {
			fclose($fp);
			return false;
		}
		$offset += 98;
		$rc = fwrite($fp, $table, $tsize);
		if ($rc != $tsize) {
			fclose($fp);
			return false;
		}
		$offset += $tsize;
		// write file
		$n = 0;
		$block = 0;
		$in = fopen($bf, "rb");
		while ($n < $bsize) {
		  $t = $bsize - $n;
		  if ($t > 4096) $t = 4096;
		  $part = fread($in, $t);
		  if ($t != strlen($part)) break;
		  $crc16 = update_app_crc16($part, $t * 8);
		  writele16($table, $block * 2, $crc16);
		  // printf("crc old = %04x, new = %04x\n", readle16($_h, 98 + $block * 2), $crc16);
		  $crc16_old = readle16($_h, 98 + $block * 2);
		  $rc = fwrite($fp, $part, $t);
		  if ($rc != $t) break;
		  $offset += $t;
		  $n += $t;
		  $block += 1;
		}
		fclose($in);
		if ($n != $bsize) {
			fclose($fp);
			return false;
		}
		// write padding
		$n = 0;
		if (($offset & 3) != 0) {
		  $n = 4 - ($offset & 3);
		  $rc = fwrite($fp, $zero, $n);
		  if ($rc != $n) {
		  	fclose($fp);
		  	return false;
		  }
		  $offset += $n;
		}
        // rebuid head #2
        $rc = fseek($fp, 0 - ($tsize + $bsize + $n), SEEK_CUR);
        if ($rc < 0) {
        	fclose($fp);
        	return false;
        }
        $rc = fwrite($fp, $table, $tsize);
        if ($rc != $tsize) {
        	fclose($fp);
        	return false;
        }
        $rc = fseek($fp, 0, SEEK_END);
        if ($rc < 0) {
        	fclose($fp);
        	return false;
        }
        // next file
        $index += 1;
	}
	fclose($fp);
	return true;
}

$argc = count($argv);
if ($argc != 3 && $argc != 4 || ($argv[1] != '-u' && $argv[1] != '-r')) {
	usage($argv);
}
if ($argv[1] == '-r' && $argc == 4)
	repack_update_app($argv[2], $argv[3]);
else if ($argv[1] == '-u') {
	if ($argc == 3)
		$d = getcwd();
	else
		$d = $argv[3];
	unpack_update_app($argv[2], $d);
}
else {
	usage($argv);
}

