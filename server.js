import crypto from "node:crypto";
import express from "express";
import http from "node:http";
import cors from "cors";
import { Server } from "socket.io";

const app = express();
const server = http.createServer(app);

const allowedOrigin = process.env.ALLOWED_ORIGIN || "*";
const relaySecret = process.env.RELAY_SECRET;

app.use(express.json({ limit: "16kb" }));
app.use(cors({ origin: allowedOrigin }));

const io = new Server(server, {
  cors: {
    origin: allowedOrigin,
    methods: ["GET", "POST"]
  }
});

app.get("/health", (_request, response) => {
  response.json({ ok: true, game: "oldmaid" });
});

io.on("connection", (socket) => {
  socket.on("join_room", (roomCode) => {
    if (typeof roomCode !== "string" || !/^[A-Z0-9]{1,10}$/i.test(roomCode)) {
      return;
    }
    socket.join(`room-${roomCode.toUpperCase()}`);
  });
});

app.post("/events/state-updated", (request, response) => {
  if (relaySecret) {
    const signature = request.get("X-Relay-Signature");
    const rawBody = JSON.stringify(request.body);
    const expectedSignature = crypto
      .createHmac("sha256", relaySecret)
      .update(rawBody)
      .digest("hex");

    if (
      !signature ||
      !crypto.timingSafeEqual(
        Buffer.from(signature),
        Buffer.from(expectedSignature)
      )
    ) {
      return response.status(401).json({ error: "Unauthorized" });
    }
  }

  const { roomCode, version } = request.body;

  if (typeof roomCode !== "string" || !Number.isInteger(version)) {
    return response.status(400).json({ error: "Invalid event payload" });
  }

  io.to(`room-${roomCode.toUpperCase()}`).emit("state_updated", { version });
  return response.status(202).json({ delivered: true });
});

server.listen(process.env.PORT || 3001, "0.0.0.0", () => {
  console.log("Old Maid realtime relay is running.");
});
