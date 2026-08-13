import 'dart:convert';

import 'package:http/http.dart' as http;

/// Raised when the attempt failed in a way that is worth repeating: no network, a timeout,
/// a 5xx, a 429. The entries stay `pending` and go out again later.
class TransientSyncFailure implements Exception {
  TransientSyncFailure(this.message);

  final String message;

  @override
  String toString() => 'TransientSyncFailure: $message';
}

/// Raised when the server refused the request itself — a 4xx on the envelope, a bad token,
/// a malformed batch. Retrying reproduces it exactly, so the entries are marked `rejected`
/// and surfaced instead of being cycled forever.
class PermanentSyncFailure implements Exception {
  PermanentSyncFailure(this.message, {this.statusCode});

  final String message;
  final int? statusCode;

  @override
  String toString() => 'PermanentSyncFailure($statusCode): $message';
}

/// What the till needs from the server, expressed without HTTP so the drain loop can be
/// driven in tests without a socket.
abstract interface class SyncTransport {
  Future<Map<String, dynamic>> push(Map<String, dynamic> batch);

  Future<Map<String, dynamic>> pull({String? since});
}

class HttpSyncTransport implements SyncTransport {
  HttpSyncTransport({
    required this.baseUrl,
    required this.token,
    http.Client? client,
    this.timeout = const Duration(seconds: 30),
  }) : _client = client ?? http.Client();

  final String baseUrl;
  final String token;
  final Duration timeout;
  final http.Client _client;

  @override
  Future<Map<String, dynamic>> push(Map<String, dynamic> batch) {
    return _send(
      () => _client
          .post(
            Uri.parse('$baseUrl/api/v1/sync/push'),
            headers: _headers,
            body: jsonEncode(batch),
          )
          .timeout(timeout),
    );
  }

  @override
  Future<Map<String, dynamic>> pull({String? since}) {
    final uri = Uri.parse('$baseUrl/api/v1/sync/pull').replace(
      queryParameters: since == null ? null : {'since': since},
    );

    return _send(() => _client.get(uri, headers: _headers).timeout(timeout));
  }

  Map<String, String> get _headers => {
        'Accept': 'application/json',
        'Content-Type': 'application/json',
        'Authorization': 'Bearer $token',
      };

  Future<Map<String, dynamic>> _send(Future<http.Response> Function() request) async {
    final http.Response response;

    try {
      response = await request();
    } on Exception catch (error) {
      // No socket, DNS failure, timeout: the shop's wifi, essentially. Always worth
      // repeating.
      throw TransientSyncFailure(error.toString());
    }

    if (response.statusCode >= 500 || response.statusCode == 429) {
      throw TransientSyncFailure('HTTP ${response.statusCode}');
    }

    if (response.statusCode >= 400) {
      throw PermanentSyncFailure(response.body, statusCode: response.statusCode);
    }

    return jsonDecode(response.body) as Map<String, dynamic>;
  }
}
