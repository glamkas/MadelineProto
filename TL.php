<?php
set_include_path(get_include_path() . PATH_SEPARATOR . dirname(__FILE__) . DIRECTORY_SEPARATOR . 'libpy2php');
require_once ('libpy2php.php');
$__author__ = 'agrigoryev';
require_once ('os.php');
class TlConstructor {
    function __construct($json_dict) {
        $this->id = pyjslib_int($json_dict['id']);
        $this->type = $json_dict['type'];
        $this->predicate = $json_dict['predicate'];
        $this->params = [];
        foreach ($json_dict['params'] as $param) {
            if (($param['type'] == 'Vector<long>')) {
                $param['type'] = 'Vector t';
                $param['subtype'] = 'long';
            } else if (($param['type'] == 'vector<%Message>')) {
                $param['type'] = 'vector';
                $param['subtype'] = 'message';
            } else if (($param['type'] == 'vector<future_salt>')) {
                $param['type'] = 'vector';
                $param['subtype'] = 'future_salt';
            } else {
                $param['subtype'] = null;
            }
            $this->params[] = $param;
        }
    }
}
class TlMethod {
    function __construct($json_dict) {
        $this->id = pyjslib_int($json_dict['id']);
        $this->type = $json_dict['type'];
        $this->method = $json_dict['method'];
        $this->params = $json_dict['params'];
    }
}
class TLObject extends ArrayObject {
    function __construct($tl_elem) {
        $this->name = $tl_elem->predicate;
    }
}
class TL {
    function __construct($filename) {
        $TL_dict = json_decode(file_get_contents($filename), true);
        $this->constructors = $TL_dict['constructors'];
        $this->constructor_id = [];
        $this->constructor_type = [];
        foreach ($this->constructors as $elem) {
            $z = new TlConstructor($elem);
            $this->constructor_id[$z->id] = $z;
            $this->constructor_type[$z->predicate] = $z;
        }
        $this->methods = $TL_dict['methods'];
        $this->method_id = [];
        $this->method_name = [];
        foreach ($this->methods as $elem) {
            $z = new TlMethod($elem);
            $this->method_id[$z->id] = $z;
            $this->method_name[$z->method] = $z;
        }
    }
}
$tl = new TL(__DIR__ . '/TL_schema.JSON');
function serialize_obj($type_, $kwargs) {
    global $tl;
    $bytes_io = fopen("php://memory", "w+b");
    try {
        $tl_constructor = $tl->constructor_type[$type_];
    }
    catch(KeyError $e) {
        throw new $Exception(sprintf('Could not extract type: %s', $type_));
    }
    fwrite($bytes_io, pack_le('i', $tl_constructor->id));
    foreach ($tl_constructor->params as $arg) {
        serialize_param($bytes_io, $arg['type'], $kwargs[$arg['name']]);
    }
    return fread_all($bytes_io);
}
function serialize_method($type_, $kwargs) {
    global $tl;
    $bytes_io = fopen("php://memory", "rw+b");

    try {
        $tl_method = $tl->method_name[$type_];
    }
    catch(KeyError $e) {
        throw new $Exception(sprintf('Could not extract type: %s', $type_));
    }
    fwrite($bytes_io, pack_le('i', $tl_method->id));
    foreach ($tl_method->params as $arg) {
        serialize_param($bytes_io, $arg['type'], $kwargs[$arg['name']]);
    }
    return fread_all($bytes_io);
}
function serialize_param($bytes_io, $type_, $value) {
    if (($type_ == 'int')) {
        assert(is_numeric($value));
        assert(($value->bit_length() <= 32));
        fwrite($bytes_io, pack_le('i', $value));
    } else if (($type_ == 'long')) {
        assert(is_numeric($value));
        fwrite($bytes_io, pack_le('q', $value));
    } else if (in_array($type_, ['int128', 'int256'])) {
        assert(!empty($value));
        fwrite($bytes_io, $value);
    } else if (($type_ == 'string') || 'bytes') {
        $l = count($value);
        if (($l < 254)) {
            fwrite($bytes_io, pack_le('b', $l));
            fwrite($bytes_io, $value);
            fwrite($bytes_io, (' ' * ((-$l - 1) % 4)));
        } else {
            fwrite($bytes_io, 'þ');
            fwrite($bytes_io, array_slice(pack_le('i', $l), null, 3));
            fwrite($bytes_io, $value);
            fwrite($bytes_io, (' ' * (-$l % 4)));
        }
    }

}
/**
 * :type bytes_io: io.BytesIO object
 */
function deserialize($bytes_io, $type_ = null, $subtype = null) {
    global $tl;
    assert(get_resource_type($bytes_io) == 'file' || get_resource_type($bytes_io) == 'stream');
    if (($type_ == 'int')) {
        $x = unpack_le('i', fread($bytes_io, 4)) [1];
    } else if (($type_ == '#')) {
        $x = unpack_le('I', fread($bytes_io, 4)) [1];
    } else if (($type_ == 'long')) {
        $x = unpack_le('q', fread($bytes_io, 8)) [1];
    } else if (($type_ == 'double')) {
        $x = unpack_le('d', fread($bytes_io, 8)) [1];
    } else if (($type_ == 'int128')) {
        $x = fread($bytes_io, 16);
    } else if (($type_ == 'int256')) {
        $x = fread($bytes_io, 32);
    } else if (($type_ == 'string') || ($type_ == 'bytes')) {
        $l = unpack_le('C', fread($bytes_io, 1)) [1];
        assert(($l <= 254));
        if (($l == 254)) {
            $long_len = unpack_le('I', fread($bytes_io, 3) . ' ') [1];
            $x = fread($bytes_io, $long_len);
            fread($bytes_io, (-$long_len % 4));
        } else {
            $x = fread($bytes_io, $l);
            //var_dump((-($l + 1) % 4));
            fread($bytes_io, (-($l + 1) % 4));
        }
        assert(is_string($x));
    } else if (($type_ == 'vector')) {
        assert(($subtype != null));
        $count = unpack_le('l', fread($bytes_io, 4)) [1];
        $x = [];
        foreach( pyjslib_range($count) as $i ) {
           $x[] = deserialize($bytes_io, $subtype);
        }
    } else {
        try {
            $tl_elem = $tl->constructor_type[$type_];
        }
        catch(Exception $e) {
            $i = unpack_le('i', fread($bytes_io, 4)) [1];
            try {
                $tl_elem = $tl->constructor_id[$i];
            }
            catch(Exception $e) {
                throw new Exception(sprintf('Could not extract type: %s', $type_));
            }
        }
        var_dump($tl_elem);

        $base_boxed_types = ['Vector t', 'Int', 'Long', 'Double', 'String', 'Int128', 'Int256'];
        if (in_array($tl_elem->type, $base_boxed_types)) {
            $x = deserialize($bytes_io, $tl_elem->predicate, $subtype);
        } else {
            $x = new TLObject($tl_elem);

            foreach ($tl_elem->params as $arg) {
                $x[$arg['name']] = deserialize($bytes_io, $arg['type'], $arg['subtype']);
            }
        }
    }
    return $x;
}
