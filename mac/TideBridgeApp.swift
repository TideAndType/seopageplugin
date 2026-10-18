import SwiftUI
import Foundation
import Security

// TideBridge is entirely first-party. Your existing WordPress installation is
// the private job inbox. Only outbound HTTPS is used; LM Studio stays on 127.0.0.1.
@main
struct TideBridgeApp: App {
    @StateObject private var agent = BridgeAgent()
    var body: some Scene {
        WindowGroup("TideBridge") {
            BridgeView(agent: agent)
                .frame(minWidth: 530, minHeight: 450)
                .onAppear { agent.resumeIfPaired() }
        }
        .defaultSize(width: 570, height: 490)
        MenuBarExtra("TideBridge", systemImage: "bolt.horizontal.circle") {
            Text(agent.connected ? "Connected to TideOrbit" : "Not connected")
            Text(agent.status)
            Divider()
            Button("Check for work now") { agent.start() }
            Button("Quit TideBridge") { NSApplication.shared.terminate(nil) }
        }
    }
}

struct BridgeView: View {
    @ObservedObject var agent: BridgeAgent
    var body: some View {
        VStack(alignment: .leading, spacing: 17) {
            HStack(spacing: 10) {
                Image(systemName: "bolt.horizontal.circle.fill")
                    .font(.system(size: 32))
                    .foregroundStyle(.tint)
                VStack(alignment: .leading) {
                    Text("TideBridge").font(.largeTitle).bold()
                    Text("Your Mac. Your AI. No tunnel.").foregroundStyle(.secondary)
                }
            }

            GroupBox("Connect to TideOrbit") {
                VStack(alignment: .leading, spacing: 11) {
                    Text("Your WordPress website")
                    TextField("https://example.com", text: $agent.website)
                        .textFieldStyle(.roundedBorder)
                        .autocorrectionDisabled()
                    Text("Six-digit code from TideOrbit → Settings → Connect my Mac")
                        .font(.caption).foregroundStyle(.secondary)
                    HStack {
                        TextField("123456", text: $agent.pairCode)
                            .textFieldStyle(.roundedBorder)
                            .frame(width: 140)
                        Button(agent.connected ? "Reconnect Mac" : "Connect Mac") { agent.pair() }
                            .buttonStyle(.borderedProminent)
                            .disabled(agent.website.isEmpty || agent.pairCode.count != 6 || agent.pairing)
                    }
                    Text("Only your website address and pairing code are needed. TideBridge stores its private token in macOS Keychain.")
                        .font(.caption).foregroundStyle(.secondary)
                }.padding(8)
            }

            GroupBox("Local LM Studio") {
                VStack(alignment: .leading, spacing: 8) {
                    HStack {
                        Text("Server")
                        TextField("http://127.0.0.1:1234/v1", text: $agent.localServer)
                            .textFieldStyle(.roundedBorder)
                    }
                    Text("Start the LM Studio local server in its Developer tab. The connection never exposes this address to the internet.")
                        .font(.caption).foregroundStyle(.secondary)
                }.padding(8)
            }

            HStack {
                Circle().fill(agent.connected ? Color.green : Color.orange).frame(width: 9, height: 9)
                Text(agent.connected ? "Paired" : "Not paired").bold()
                Spacer()
                Button("Disconnect this Mac") { agent.disconnect() }
                    .disabled(!agent.connected)
                Button("Check now") { agent.start() }.disabled(!agent.connected)
            }
            Text(agent.status)
                .foregroundStyle(.secondary)
                .textSelection(.enabled)
            if !agent.lastJob.isEmpty {
                Text(agent.lastJob).font(.callout).textSelection(.enabled)
            }
            Spacer()
            Text("TideBridge keeps working while this app is open. WordPress queues requests; your Mac processes them and saves drafts back automatically.")
                .font(.caption).foregroundStyle(.secondary)
        }
        .padding(22)
    }
}

enum BridgeProblem: LocalizedError {
    case message(String)
    var errorDescription: String? {
        switch self { case .message(let value): return value }
    }
}

enum SecureToken {
    private static let service = "com.tideandtype.TideBridge"
    private static let account = "wordpress-local-ai"
    static func read() -> String? {
        let query: [String: Any] = [
            kSecClass as String: kSecClassGenericPassword,
            kSecAttrService as String: service,
            kSecAttrAccount as String: account,
            kSecReturnData as String: true,
            kSecMatchLimit as String: kSecMatchLimitOne
        ]
        var result: CFTypeRef?
        guard SecItemCopyMatching(query as CFDictionary, &result) == errSecSuccess,
              let data = result as? Data else { return nil }
        return String(data: data, encoding: .utf8)
    }
    static func write(_ secret: String) throws {
        let key: [String: Any] = [
            kSecClass as String: kSecClassGenericPassword,
            kSecAttrService as String: service,
            kSecAttrAccount as String: account
        ]
        SecItemDelete(key as CFDictionary)
        var record = key
        record[kSecValueData as String] = Data(secret.utf8)
        record[kSecAttrAccessible as String] = kSecAttrAccessibleAfterFirstUnlockThisDeviceOnly
        let status = SecItemAdd(record as CFDictionary, nil)
        if status != errSecSuccess { throw BridgeProblem.message("Could not save pairing securely in Keychain (\(status)).") }
    }
    static func clear() {
        let query: [String: Any] = [
            kSecClass as String: kSecClassGenericPassword,
            kSecAttrService as String: service,
            kSecAttrAccount as String: account
        ]
        SecItemDelete(query as CFDictionary)
    }
}

final class NoRedirects: NSObject, URLSessionTaskDelegate {
    func urlSession(_ session: URLSession, task: URLSessionTask,
                    willPerformHTTPRedirection response: HTTPURLResponse,
                    newRequest request: URLRequest,
                    completionHandler: @escaping (URLRequest?) -> Void) {
        completionHandler(nil)
    }
}

@MainActor
final class BridgeAgent: ObservableObject {
    @Published var website = UserDefaults.standard.string(forKey: "website") ?? ""
    @Published var pairCode = ""
    @Published var localServer = UserDefaults.standard.string(forKey: "server") ?? "http://127.0.0.1:1234/v1"
    @Published var status = "Pair once to connect TideOrbit to the LM Studio on this Mac."
    @Published var lastJob = ""
    @Published var connected = SecureToken.read() != nil
    @Published var pairing = false

    private var polling: Task<Void, Never>?
    private let redirects = NoRedirects()
    private lazy var session: URLSession = {
        let configuration = URLSessionConfiguration.ephemeral
        configuration.timeoutIntervalForRequest = 40
        configuration.timeoutIntervalForResource = 80
        return URLSession(configuration: configuration, delegate: redirects, delegateQueue: nil)
    }()

    func resumeIfPaired() { if connected { start() } }
    func disconnect() {
        polling?.cancel()
        polling = nil
        SecureToken.clear()
        connected = false
        pairCode = ""
        status = "Disconnected locally. To revoke the old Mac token on WordPress, use Disconnect on TideOrbit's Mac Connection page."
    }
    func pair() {
        guard !pairing else { return }
        guard let root = rootURL() else {
            status = "Enter your site's full HTTPS address (for example, https://example.com)."
            return
        }
        pairing = true
        status = "Checking pairing code…"
        let code = pairCode
        Task {
            defer { pairing = false }
            do {
                let body = try await post(root: root, path: "pair", values: ["code": code], secret: nil)
                guard let token = body["token"] as? String, token.count >= 48 else {
                    throw BridgeProblem.message("WordPress did not return a valid pairing token.")
                }
                try SecureToken.write(token)
                UserDefaults.standard.set(website, forKey: "website")
                UserDefaults.standard.set(localServer, forKey: "server")
                pairCode = ""
                connected = true
                status = "Connected. Checking TideOrbit for work."
                start()
            } catch {
                status = "Pairing failed: \(error.localizedDescription)"
            }
        }
    }
    private func rootURL() -> URL? {
        let text = website.trimmingCharacters(in: .whitespacesAndNewlines).trimmingCharacters(in: CharacterSet(charactersIn: "/"))
        guard let url = URL(string: text), url.scheme == "https", url.host != nil,
              url.user == nil, url.password == nil, url.query == nil, url.fragment == nil else { return nil }
        return url
    }
    private func post(root: URL, path: String, values: [String: Any], secret: String?) async throws -> [String: Any] {
        guard let url = URL(string: root.absoluteString + "/wp-json/seo-command/v1/mac/" + path) else {
            throw BridgeProblem.message("Invalid WordPress REST API address.")
        }
        var request = URLRequest(url: url)
        request.httpMethod = "POST"
        request.setValue("application/json", forHTTPHeaderField: "Content-Type")
        if let secret = secret { request.setValue(secret, forHTTPHeaderField: "X-TideBridge-Key") }
        request.httpBody = try JSONSerialization.data(withJSONObject: values)
        let (body, response) = try await session.data(for: request)
        guard let http = response as? HTTPURLResponse else {
            throw BridgeProblem.message("No response from WordPress.")
        }
        let json = (try? JSONSerialization.jsonObject(with: body)) as? [String: Any] ?? [:]
        guard (200..<300).contains(http.statusCode) else {
            throw BridgeProblem.message((json["message"] as? String) ?? "WordPress replied HTTP \(http.statusCode).")
        }
        return (json["data"] as? [String: Any]) ?? [:]
    }

    func start() {
        guard connected else { return }
        if polling != nil { return }
        guard let root = rootURL() else {
            status = "WordPress URL is invalid. Enter an HTTPS address and reconnect."
            return
        }
        guard let token = SecureToken.read() else { connected = false; return }
        UserDefaults.standard.set(website, forKey: "website")
        UserDefaults.standard.set(localServer, forKey: "server")
        polling = Task {
            status = "Connected. Checking for new WordPress jobs…"
            while !Task.isCancelled {
                do {
                    let data = try await post(root: root, path: "next", values: [:], secret: token)
                    if let job = data["job"] as? [String: Any] {
                        await handle(job, root: root, token: token)
                    } else {
                        status = "Connected. Waiting for a TideOrbit article…"
                    }
                } catch {
                    status = "WordPress connection: \(error.localizedDescription). Retrying…"
                }
                try? await Task.sleep(for: .seconds(5))
            }
            polling = nil
        }
    }

    private func handle(_ job: [String: Any], root: URL, token: String) async {
        guard let id = job["id"] as? Int,
              let lease = job["lease"] as? String else {
            status = "WordPress returned an invalid job."
            return
        }
        let base: [String: Any] = ["id": id, "lease": lease]
        if job["finalize_only"] as? Bool == true {
            status = "Finishing article #\(id) previously written on this Mac…"
            do {
                let saved = try await post(root: root, path: "finalize", values: base, secret: token)
                if saved["state"] as? String == "done" {
                    lastJob = "Article #\(id) saved to WordPress as post #\(saved["post_id"] ?? 0)."
                    status = "Connected. Waiting for another TideOrbit article…"
                } else {
                    status = (saved["error"] as? String) ?? "WordPress did not finish saving the article."
                }
            } catch {
                status = "Saving article #\(id): \(error.localizedDescription). Retrying…"
            }
            return
        }
        guard let request = job["request"] as? [String: Any] else {
            status = "WordPress returned a job without an AI prompt."
            return
        }
        status = "LM Studio is writing article #\(id)…"
        do {
            let result = try await generate(request)
            var complete = base
            complete["content"] = result
            _ = try await post(root: root, path: "complete", values: complete, secret: token)
            status = "Article #\(id) written. Saving it in WordPress…"
            let saved = try await post(root: root, path: "finalize", values: base, secret: token)
            if saved["state"] as? String == "done" {
                lastJob = "Article #\(id) saved to WordPress as post #\(saved["post_id"] ?? 0)."
                status = "Connected. Waiting for another TideOrbit article…"
            } else {
                throw BridgeProblem.message((saved["error"] as? String) ?? "WordPress could not save the completed article.")
            }
        } catch {
            status = "Article #\(id): \(error.localizedDescription)"
            lastJob = status
            // Do not mark a successfully generated job as failed just because
            // finalize failed. Its completed response is still held in WordPress.
            do {
                let state = try await post(root: root, path: "status", values: ["id": id], secret: token)
                if state["state"] as? String == "working" {
                    var complete = base
                    complete["error"] = error.localizedDescription
                    _ = try? await post(root: root, path: "complete", values: complete, secret: token)
                }
            } catch { }
        }
    }

    private func generate(_ job: [String: Any]) async throws -> String {
        let local = localServer.trimmingCharacters(in: .whitespacesAndNewlines).trimmingCharacters(in: CharacterSet(charactersIn: "/"))
        guard let localURL = URL(string: local), localURL.scheme == "http",
              ["127.0.0.1", "localhost", "::1"].contains(localURL.host ?? ""),
              localURL.user == nil, localURL.password == nil,
              let url = URL(string: local + "/chat/completions") else {
            throw BridgeProblem.message("LM Studio must run locally, such as http://127.0.0.1:1234/v1.")
        }
        var messages: [[String: Any]] = []
        if let system = job["system"] as? String, !system.isEmpty {
            messages.append(["role": "system", "content": system])
        }
        if let history = job["messages"] as? [[String: Any]] {
            for msg in history {
                let role = msg["role"] as? String ?? "user"
                if role == "user" || role == "assistant" || role == "system" {
                    messages.append(["role": role, "content": msg["content"] ?? ""])
                }
            }
        }
        guard !messages.isEmpty else { throw BridgeProblem.message("The article prompt is empty.") }
        var payload: [String: Any] = [
            "model": (job["model"] as? String).flatMap { $0.isEmpty ? nil : $0 } ?? "local-model",
            "messages": messages,
            "max_tokens": -1,
            "stream": false
        ]
        if let temperature = job["temperature"] { payload["temperature"] = temperature }
        var req = URLRequest(url: url)
        req.httpMethod = "POST"
        req.httpBody = try JSONSerialization.data(withJSONObject: payload)
        req.setValue("application/json", forHTTPHeaderField: "Content-Type")
        let conf = URLSessionConfiguration.ephemeral
        conf.timeoutIntervalForRequest = 7200
        conf.timeoutIntervalForResource = 7200
        let localSession = URLSession(configuration: conf, delegate: redirects, delegateQueue: nil)
        defer { localSession.invalidateAndCancel() }
        var (data, response) = try await localSession.data(for: req)
        // Older LM Studio models sometimes reject sampling options. The
        // WordPress provider already retries without them, so keep parity.
        if let http = response as? HTTPURLResponse, http.statusCode == 400,
           payload["temperature"] != nil {
            payload.removeValue(forKey: "temperature")
            req.httpBody = try JSONSerialization.data(withJSONObject: payload)
            (data, response) = try await localSession.data(for: req)
        }
        guard let http = response as? HTTPURLResponse, http.statusCode == 200 else {
            let msg = String(data: data.prefix(450), encoding: .utf8) ?? "No response"
            throw BridgeProblem.message("LM Studio failed: \(msg)")
        }
        guard let decoded = try JSONSerialization.jsonObject(with: data) as? [String: Any],
              let choices = decoded["choices"] as? [[String: Any]],
              let message = choices.first?["message"] as? [String: Any],
              let content = message["content"] as? String,
              content.count > 100 else {
            throw BridgeProblem.message("LM Studio returned no usable article. Check the model and LM Studio server.")
        }
        return content
    }
}
