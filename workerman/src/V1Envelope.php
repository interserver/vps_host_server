<?php

namespace MyAdmin\VpsHost;

/**
* V1Envelope - agent-side helper for building/parsing PROTOCOL_V1 frames
* (datacentered docs/PROTOCOL_V1.md §1).
*
* Request shape:  {v:1, id:<uuid>, op:"ns.verb", ts:<unix>, data:{...}, enc?:"gzip"}
* Reply shape:    {v:1, re:<id>, ok:true, data:{...}} / {v:1, re:<id>, ok:false, error:{code,message}}
*
* This is the agent-side equivalent of the hub's Events::isV1Envelope()/
* v1Envelope()/v1DecodeEnvelopeData() (separate repo - no hub code imported).
* Detection is strict so legacy {type:"..."} frames can never match: legacy
* frames never carry v/op/re, v1 frames never carry type.
*/
class V1Envelope
{
	/**
	* strict v1 REQUEST-envelope detector (mirrors the hub's isV1Envelope()):
	* v===1, non-empty string id + op, int ts, and data is an array (or a
	* string when enc:"gzip"). No decoding happens here - see decodeData().
	*
	* @param mixed $data json_decode()d frame
	* @return bool
	*/
	public static function isRequest($data): bool
	{
		return is_array($data)
			&& isset($data['op']) && is_string($data['op']) && $data['op'] !== ''
			&& isset($data['v']) && $data['v'] === 1
			&& isset($data['id']) && is_string($data['id']) && $data['id'] !== ''
			&& isset($data['ts']) && is_int($data['ts'])
			&& array_key_exists('data', $data)
			&& (is_array($data['data'])
				|| (isset($data['enc']) && $data['enc'] === 'gzip' && is_string($data['data'])));
	}

	/**
	* strict v1 REPLY-envelope detector: v===1, non-empty string re, bool ok,
	* plus data (ok:true) or error (ok:false). Replies have no op (§1) -
	* receivers correlate by re.
	*
	* @param mixed $data json_decode()d frame
	* @return bool
	*/
	public static function isReply($data): bool
	{
		if (!is_array($data)
			|| !isset($data['v']) || $data['v'] !== 1
			|| !isset($data['re']) || !is_string($data['re']) || $data['re'] === ''
			|| !isset($data['ok']) || !is_bool($data['ok'])) {
			return false;
		}
		if ($data['ok'] === true) {
			return array_key_exists('data', $data)
				&& (is_array($data['data'])
					|| (isset($data['enc']) && $data['enc'] === 'gzip' && is_string($data['data'])));
		}
		return isset($data['error']) && is_array($data['error']);
	}

	/**
	* is this decoded frame ANY v1 envelope (request or reply)?
	*
	* @param mixed $data json_decode()d frame
	* @return bool
	*/
	public static function isV1($data): bool
	{
		return self::isRequest($data) || self::isReply($data);
	}

	/**
	* build a v1 request envelope (fresh uuid id, current ts).
	*
	* @param string $op   namespace.verb op name
	* @param array $data  op payload
	* @param bool $gzip   true = send data as enc:"gzip" base64(gzcompress(json)) per §1
	* @return array envelope ready for json_encode()
	*/
	public static function request(string $op, array $data = [], bool $gzip = false): array
	{
		$envelope = [
			'v' => 1,
			'id' => self::uuid(),
			'op' => $op,
			'ts' => time(),
			'data' => $data
		];
		if ($gzip) {
			$envelope['enc'] = 'gzip';
			$envelope['data'] = base64_encode(gzcompress(json_encode($data), 9));
		}
		return $envelope;
	}

	/**
	* build a v1 ok:true reply to request id $re.
	*
	* @param string $re   the request id being answered
	* @param array $data  result payload
	* @param bool $gzip   true = send data as enc:"gzip" per §1
	* @return array
	*/
	public static function reply(string $re, array $data = [], bool $gzip = false): array
	{
		$envelope = [
			'v' => 1,
			're' => $re,
			'ok' => true,
			'data' => $data
		];
		if ($gzip) {
			$envelope['enc'] = 'gzip';
			$envelope['data'] = base64_encode(gzcompress(json_encode($data), 9));
		}
		return $envelope;
	}

	/**
	* build a v1 ok:false error reply to request id $re.
	*
	* @param string $re      the request id being answered
	* @param string $code    stable machine-readable code (bad_request, internal, ...)
	* @param string $message human-readable detail
	* @return array
	*/
	public static function error(string $re, string $code, string $message): array
	{
		return [
			'v' => 1,
			're' => $re,
			'ok' => false,
			'error' => ['code' => $code, 'message' => $message]
		];
	}

	/**
	* decode an envelope's optional enc:"gzip" data in place (§1: enc's only
	* legal value is "gzip"; data is base64(gzcompress(json))). Plain envelopes
	* pass through untouched. Returns false on any malformed input so callers
	* can reply bad_request instead of crashing.
	*
	* @param array $envelope v1 envelope (modified in place on success)
	* @return bool true when $envelope['data'] is a usable array afterwards
	*/
	public static function decodeData(array &$envelope): bool
	{
		if (!isset($envelope['enc'])) {
			return isset($envelope['data']) && is_array($envelope['data']);
		}
		if ($envelope['enc'] !== 'gzip' || !is_string($envelope['data'])) {
			return false;
		}
		$raw = base64_decode($envelope['data'], true);
		if ($raw === false) {
			return false;
		}
		$json = @gzuncompress($raw);
		if ($json === false) {
			return false;
		}
		$data = json_decode($json, true);
		if (!is_array($data)) {
			return false;
		}
		$envelope['data'] = $data;
		unset($envelope['enc']);
		return true;
	}

	/**
	* RFC-4122 v4 uuid from random_bytes.
	*
	* @return string
	*/
	public static function uuid(): string
	{
		$bytes = random_bytes(16);
		$bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
		$bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
		return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
	}
}
