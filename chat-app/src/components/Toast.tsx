export function Toast({ message }: { message: string }) {
  return (
    <div id="chat-toast" className="show">
      {message}
    </div>
  );
}
